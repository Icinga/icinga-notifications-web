<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Sync;

use Icinga\Module\Notifications\Sync\ContactSyncPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ContactSyncPlanner}
 *
 * Channel 1 is an email channel and the default for new contacts, channel 2 is a webhook channel.
 */
class ContactSyncPlannerTest extends TestCase
{
    private function planner(bool $purge = false): ContactSyncPlanner
    {
        return new ContactSyncPlanner('LDAP users', 1, [1 => 'email', 2 => 'webhook'], $purge);
    }

    /**
     * @param string[] $groups
     * @param array<string, ?string> $addresses
     */
    private function row(string $username, array $groups = [], array $addresses = [], ?string $fullName = null): array
    {
        return [
            'username'  => $username,
            'fullName'  => $fullName,
            'addresses' => $addresses + ['email' => "$username@example.com"],
            'groups'    => $groups
        ];
    }

    private function contact(int $id, string $username, ?string $uuid, array $groups = [], array $addresses = []): array
    {
        return [
            'id'        => $id,
            'username'  => $username,
            'fullName'  => $username,
            'channelId' => 1,
            'uuid'      => $uuid,
            'addresses' => $addresses + ['email' => "$username@example.com"],
            'groups'    => $groups
        ];
    }

    public function testExtractRowReducesMultiValuedPropertiesAndCollectsGroups(): void
    {
        $row = ContactSyncPlanner::extractRow(
            (object) [
                'sAMAccountName' => ' jdoe ',
                'displayName'    => '',
                'mail'           => ['jdoe@example.com', 'john@example.com'],
                'memberOf'       => ['Admins', ' ', 'Ops']
            ],
            'sAMAccountName',
            'displayName',
            ['email' => 'mail', 'sms' => 'mobile'],
            'memberOf'
        );

        $this->assertSame([
            'username'  => 'jdoe',
            'fullName'  => null,
            'addresses' => ['email' => 'jdoe@example.com', 'sms' => null],
            'groups'    => ['Admins', 'Ops']
        ], $row);
    }

    public function testCreatesMissingContactsAndGroupsWithOwnedUuids(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([$this->row('jdoe', ['Ops', 'Admins'], [], 'John Doe')], [], [
            ['id' => 7, 'name' => 'admins', 'uuid' => null]
        ]);

        $this->assertSame(['ops' => ['name' => 'Ops', 'uuid' => $planner->groupUuid('Ops')]], $plan->createGroups);
        $this->assertSame([[
            'username'  => 'jdoe',
            'fullName'  => 'John Doe',
            'channelId' => 1,
            'addresses' => ['email' => 'jdoe@example.com'],
            'groups'    => ['ops', 7],
            'uuid'      => $planner->contactUuid('JDOE')
        ]], $plan->createContacts);
        $this->assertNotSame($planner->contactUuid('jdoe'), $this->planner()->groupUuid('jdoe'));
        $this->assertNotSame(
            $planner->contactUuid('jdoe'),
            (new ContactSyncPlanner('Other', 1, []))->contactUuid('jdoe')
        );
    }

    public function testUpdatePreservesUnmanagedAddressesAndMemberships(): void
    {
        $planner = $this->planner();
        $groups = [
            ['id' => 1, 'name' => 'Manual', 'uuid' => '00000000-0000-0000-0000-000000000001'],
            ['id' => 2, 'name' => 'Old', 'uuid' => $planner->groupUuid('Old')],
            ['id' => 3, 'name' => 'Ops', 'uuid' => '00000000-0000-0000-0000-000000000003']
        ];
        $contact = $this->contact(5, 'JDoe', null, [1, 2], ['sms' => '+49123']);

        $plan = $planner->plan([$this->row('jdoe', ['ops'], ['email' => 'new@example.com'])], [$contact], $groups);

        $this->assertSame([[
            'id'        => 5,
            'username'  => 'JDoe',
            'fullName'  => 'jdoe',
            'channelId' => 1,
            'addresses' => ['sms' => '+49123', 'email' => 'new@example.com'],
            'groups'    => [1, 3]
        ]], $plan->updateContacts);
        $this->assertSame([], $plan->createGroups);
    }

    public function testUnchangedContactsAreNotUpdated(): void
    {
        $plan = $this->planner()->plan(
            [$this->row('jdoe', ['Ops'], [], 'jdoe')],
            [$this->contact(5, 'jdoe', null, [3])],
            [['id' => 3, 'name' => 'Ops', 'uuid' => null]]
        );

        $this->assertFalse($plan->hasChanges());
    }

    public function testPurgeOnlyDeletesOwnedContacts(): void
    {
        $planner = $this->planner(true);
        $contacts = [
            $this->contact(1, 'owned', $planner->contactUuid('owned')),
            $this->contact(2, 'manual', '00000000-0000-0000-0000-000000000002'),
            $this->contact(3, 'other-sync', (new ContactSyncPlanner('Other', 1, []))->contactUuid('other-sync'))
        ];

        $this->assertSame([1 => 'owned'], $planner->plan([], $contacts, [])->deleteContacts);
        $this->assertSame([], $this->planner()->plan([], $contacts, [])->deleteContacts);
    }

    public function testSkipsInvalidAndDuplicateRows(): void
    {
        $plan = $this->planner()->plan([
            $this->row(''),
            $this->row(str_repeat('x', ContactSyncPlanner::MAX_USERNAME_LENGTH + 1)),
            $this->row('jdoe'),
            $this->row('JDOE'),
            $this->row('nomail', [], ['email' => null])
        ], [], []);

        $this->assertSame(['jdoe'], array_column($plan->createContacts, 'username'));
        $this->assertCount(4, $plan->warnings);
    }

    public function testContactsWithAddresslessChannelNeedNoAddress(): void
    {
        $contact = ['channelId' => 2] + $this->contact(9, 'hook', null);

        $plan = $this->planner()->plan([$this->row('hook', [], ['email' => null])], [$contact], []);

        $this->assertSame([], $plan->warnings);
        $this->assertSame([], $plan->updateContacts[0]['addresses']);
    }
}
