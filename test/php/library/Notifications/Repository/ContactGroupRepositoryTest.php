<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Form\Data\EscalationRecipient;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\ContactgroupMember;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Repository\ContactGroupRepository;
use Icinga\Module\Notifications\Repository\EscalationRepository;
use Icinga\Module\Notifications\Repository\RotationRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see ContactGroupRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. A channel and three member contacts are seeded per test in
 * {@see self::initializeNotificationsDb()} and the contacts' ids captured into {@see self::$contactIds} (the ids
 * can't be assumed as rolled-back transactions still advance the auto-increment).
 */
#[TransactionIsolation]
class ContactGroupRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int[] Ids of the three contacts seeded per test, in insertion order */
    private static array $contactIds;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        $now = (int) (new DateTime())->format('Uv');

        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('channel', [
            'external_uuid' => '00000000-0000-0000-0000-0000000000c1', 'name' => 'Test', 'type' => 'email',
            'changed_at' => $now
        ]);
        $channelId = (int) $db->lastInsertId();

        // Three contacts to be used as group members
        self::$contactIds = [];
        foreach ([1, 2, 3] as $i) {
            $db->insert('contact', [
                'full_name' => "Contact $i", 'username' => "contact$i", 'default_channel_id' => $channelId,
                'external_uuid' => sprintf('00000000-0000-0000-0000-00000000000%d', $i), 'changed_at' => $now
            ]);
            self::$contactIds[] = (int) $db->lastInsertId();
        }
    }

    /**
     * Get the group's current (non-deleted) member contact ids, sorted
     *
     * @param Connection $db
     * @param int $groupId
     *
     * @return list<int>
     */
    private function membersOf(Connection $db, int $groupId): array
    {
        $members = [];
        $query = ContactgroupMember::on($db)
            ->filter(Filter::equal('contactgroup_id', $groupId));
        foreach ($query as $member) {
            $members[] = (int) $member->contact_id;
        }

        sort($members);

        return $members;
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheGroupDoesNotExist(Connection $db): void
    {
        $this->assertNull((new ContactGroupRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheGroupWithMemberships(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        [$c1, $c2] = self::$contactIds;

        $id = $repository->create(new ContactGroupData(null, 'Group A', [$c1, $c2]));

        $group = $repository->find($id);
        $this->assertNotNull($group, 'The created group was not found');
        $this->assertSame('Group A', $group->name);

        $this->assertSame([$c1, $c2], $this->membersOf($db, $id), 'The members were not linked');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateSyncsNameAndMembers(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        [$c1, $c2, $c3] = self::$contactIds;
        $id = $repository->create(new ContactGroupData(null, 'Old Name', [$c1, $c2]));

        // $c1 dropped, $c2 retained, $c3 added
        $repository->update(new ContactGroupData($id, 'New Name', [$c2, $c3]));

        $group = $repository->find($id);
        $this->assertSame('New Name', $group->name);

        $this->assertSame([$c2, $c3], $this->membersOf($db, $id), 'The membership set was not synced');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheGroupHasNoId(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ContactGroupRepository($db))->update(new ContactGroupData(null, 'Group A', []));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheGroupDoesNotExist(Connection $db): void
    {
        $this->expectException(RuntimeException::class);

        (new ContactGroupRepository($db))->update(new ContactGroupData(999, 'Group A', []));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheGroupAndItsMemberships(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        [$c1, $c2] = self::$contactIds;
        $id = $repository->create(new ContactGroupData(null, 'Doomed', [$c1, $c2]));

        $repository->delete($id);

        // The repository hides deleted groups
        $this->assertNull($repository->find($id), 'A deleted group must not be found anymore');

        // But it's only soft-deleted: the row still exists, flagged deleted
        $group = $this->loadRawEntity($db, $id, ContactGroup::class);
        $this->assertNotNull($group, 'The group row should still exist');
        $this->assertSame('y', $group->deleted, 'The group should be soft-deleted, not removed');

        $this->assertSame([], $this->membersOf($db, $id), 'The memberships should be soft-deleted too');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheGroupDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ContactGroupRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteDereferencesEscalationsThatOnlyTargetTheGroup(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        [$c1] = self::$contactIds;
        $groupId = $repository->create(new ContactGroupData(null, 'Group', [$c1]));

        $escalationId = $this->createEscalationTargeting($db, $groupId);

        $repository->delete($groupId);

        $this->assertNull($repository->find($groupId), 'The group should have been deleted');
        $this->assertNull(
            (new EscalationRepository($db))->find($escalationId),
            'An escalation solely targeting the deleted group should be dereferenced/removed'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSucceedsWhenAReferencedEscalationWasAlreadyDeleted(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        [$c1] = self::$contactIds;
        $groupId = $repository->create(new ContactGroupData(null, 'Group', [$c1]));

        $escalationId = $this->createEscalationTargeting($db, $groupId);

        // The escalation is removed independently first (e.g. via its rule) …
        (new EscalationRepository($db))->delete($escalationId);

        // … deleting the group must still succeed and not choke on the already soft-deleted escalation
        $repository->delete($groupId);

        $this->assertNull($repository->find($groupId), 'The group should have been deleted');
        $this->assertSame(
            'y',
            $this->loadRawEntity($db, $groupId, Contactgroup::class)->deleted,
            'The group should be soft-deleted'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteRenumbersSurvivingRotationsWithoutColliding(Connection $db): void
    {
        $repository = new ContactGroupRepository($db);
        $g1 = $repository->create(new ContactGroupData(null, 'G1'));
        $g2 = $repository->create(new ContactGroupData(null, 'G2'));

        $db->insert('schedule', [
            'name' => 'S', 'timezone' => 'Europe/Berlin', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);
        $scheduleId = (int) $db->lastInsertId();

        // A sole-owned rotation BELOW two rotations shared with G2. Deleting G1 drops the sole one (which renumbers
        // the rotations above it) and recreates the two shared ones for G2. The delete must visit rotations
        // highest-priority-first, otherwise the shared ones get recreated from stale priorities and collide on
        // (schedule_id, priority, first_handoff).
        $this->createRotation($db, $scheduleId, 0, [['contact_group', $g1]]);
        $this->createRotation($db, $scheduleId, 1, [['contact_group', $g1], ['contact_group', $g2]]);
        $this->createRotation($db, $scheduleId, 2, [['contact_group', $g1], ['contact_group', $g2]]);

        $repository->delete($g1);

        // The sole rotation is gone; the two shared ones survive with contiguous priorities and only G2 as member
        $remaining = $this->rotationsOf($db, $scheduleId);
        $this->assertSame([0, 1], array_map(fn ($r) => (int) $r->priority, $remaining), 'Survivors must be contiguous');
        foreach ($remaining as $rotation) {
            $this->assertSame(
                [$g2],
                $this->rotationMemberGroupIds($db, (int) $rotation->id),
                'Only G2 should remain a member of each surviving rotation'
            );
        }
    }

    /**
     * Fetch the non-deleted rotations of the given schedule, ordered by priority
     *
     * @param Connection $db
     * @param int $scheduleId
     *
     * @return Rotation[]
     */
    private function rotationsOf(Connection $db, int $scheduleId): array
    {
        return iterator_to_array(
            Rotation::on($db)
                ->filter(Filter::equal('schedule_id', $scheduleId))
                ->filter(Filter::equal('deleted', false))
                ->orderBy('priority')
        );
    }

    /**
     * Create a simple 24/7 rotation in the given schedule with the given members
     *
     * @param Connection $db
     * @param int $scheduleId
     * @param int $priority
     * @param array<array{string, int}> $members
     *
     * @return void
     */
    private function createRotation(Connection $db, int $scheduleId, int $priority, array $members): void
    {
        (new RotationRepository($db))->create(new RotationData(
            null,
            $scheduleId,
            $priority,
            'Rotation',
            '24-7',
            ['interval' => 1, 'frequency' => 'd', 'at' => '09:00'],
            $members,
            new DateTimeImmutable('2026-01-05 00:00', new DateTimeZone('Europe/Berlin'))
        ));
    }

    /**
     * Get the active member contact group ids of the given rotation, sorted
     *
     * @param Connection $db
     * @param int $rotationId
     *
     * @return list<int>
     */
    private function rotationMemberGroupIds(Connection $db, int $rotationId): array
    {
        $ids = [];
        $query = RotationMember::on($db)
            ->filter(Filter::equal('rotation_id', $rotationId))
            ->filter(Filter::equal('deleted', false));
        foreach ($query as $member) {
            $ids[] = (int) $member->contactgroup_id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * Create a rule with a single escalation that targets only the given contact group and return the escalation's id
     *
     * @param Connection $db
     * @param int $groupId
     *
     * @return int
     */
    private function createEscalationTargeting(Connection $db, int $groupId): int
    {
        $now = (int) (new DateTime())->format('Uv');
        $db->insert('source', ['type' => 'icinga2', 'name' => 'S', 'listener_username' => 'ls', 'changed_at' => $now]);
        $sourceId = (int) $db->lastInsertId();
        $db->insert('rule', ['name' => 'R', 'source_id' => $sourceId, 'changed_at' => $now]);
        $ruleId = (int) $db->lastInsertId();

        return (new EscalationRepository($db))->create(new Escalation(
            null,
            0,
            null,
            [new EscalationRecipient(null, 'contact_group', $groupId, null)],
            $ruleId
        ));
    }
}
