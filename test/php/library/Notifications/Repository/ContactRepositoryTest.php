<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Form\Data\Contact as ContactData;
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use Icinga\Module\Notifications\Model\ContactgroupMember;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Repository\ContactGroupRepository;
use Icinga\Module\Notifications\Repository\ContactRepository;
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
 * Tests for {@see ContactRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. The prerequisite channel is seeded per test in
 * {@see self::initializeNotificationsDb()} and its id captured into {@see self::$channelId} (the id can't be assumed
 * as rolled-back transactions still advance the auto-increment).
 *
 * What these tests do not cover:
 * - The handling of a contact's rotations and escalation references on deletion (delegated to the rotation and
 *   escalation repositories); the contacts used here have neither.
 */
#[TransactionIsolation]
class ContactRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int Id of the channel seeded per test (auto-increment drifts across rolled-back transactions) */
    private static int $channelId;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        $now = (int) (new DateTime())->format('Uv');

        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('channel', [
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000c1'),
            'name' => 'Test', 'type' => 'email',
            'changed_at' => $now
        ]);
        self::$channelId = (int) $db->lastInsertId();
    }

    /**
     * Get the ids of the contact groups the contact is currently (non-deleted) a member of, sorted
     *
     * @param Connection $db
     * @param int $contactId
     *
     * @return list<int>
     */
    private function groupsOf(Connection $db, int $contactId): array
    {
        $groups = [];
        $query = ContactgroupMember::on($db)
            ->filter(Filter::equal('contact_id', $contactId))
            ->orderBy('contactgroup_id');
        foreach ($query as $member) {
            $groups[] = (int) $member->contactgroup_id;
        }

        return $groups;
    }

    /**
     * Get the contact's non-deleted addresses as a type => address map
     *
     * @param Connection $db
     * @param int $contactId
     *
     * @return array<string, string>
     */
    private function addressesByType(Connection $db, int $contactId): array
    {
        $addresses = [];
        $query = ContactAddress::on($db)
            ->filter(Filter::equal('contact_id', $contactId));
        foreach ($query as $address) {
            $addresses[$address->type] = $address->address;
        }

        return $addresses;
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheContactDoesNotExist(Connection $db): void
    {
        $this->assertNull((new ContactRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testFindByUsernameFindsTheContactOfTheGivenUser(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(null, 'John Doe', 'finder', self::$channelId, []));

        $contact = $repository->findByUsername('finder');
        $this->assertNotNull($contact, 'The contact of the given user was not found');
        $this->assertEquals($id, $contact->id);

        $this->assertNull($repository->findByUsername('nobody'), 'A user without a contact must not match one');
    }

    #[DataProvider('sharedDatabases')]
    public function testFindByUsernameIgnoresDeletedContacts(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(null, 'John Doe', 'doomed', self::$channelId, []));
        $db->update('contact', ['deleted' => 'y'], ['id = ?' => $id]);

        $this->assertNull($repository->findByUsername('doomed'), 'A deleted contact must not be found anymore');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheContactWithAddresses(Connection $db): void
    {
        $repository = new ContactRepository($db);

        $id = $repository->create(new ContactData(
            null,
            'John Doe',
            'jdoe',
            self::$channelId,
            ['email' => 'jdoe@example.com', 'sms' => '12345']
        ));

        $contact = $repository->find($id);
        $this->assertNotNull($contact, 'The created contact was not found');
        $this->assertSame('John Doe', $contact->full_name);
        $this->assertSame('jdoe', $contact->username);
        $this->assertEquals(self::$channelId, $contact->default_channel_id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $contact->external_uuid,
            'A v4 UUID should have been generated'
        );

        $this->assertSame(
            ['email' => 'jdoe@example.com', 'sms' => '12345'],
            $this->addressesByType($db, $id)
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresContactsWithoutAUsernameAsNull(Connection $db): void
    {
        // A contact not associated with an Icinga Web user must have a NULL username, never an empty string:
        // username is unique (uk_contact_username) and empty strings would collide, whereas NULLs don't. The
        // form (ContactForm::getContact()) is responsible for passing null; this locks in that multiple such
        // contacts coexist.
        $repository = new ContactRepository($db);

        $first = $repository->create(new ContactData(null, 'First', null, self::$channelId, []));
        $second = $repository->create(new ContactData(null, 'Second', null, self::$channelId, []));

        $this->assertNull($repository->find($first)->username, 'The first contact\'s username must be null');
        $this->assertNull($repository->find($second)->username, 'The second contact\'s username must be null');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheGivenExternalUuid(Connection $db): void
    {
        // The V1 API lets clients choose the UUID a contact is referenced by, so a given one must win
        // over a generated one
        $uuid = '00000000-0000-4000-8000-0000000000e1';
        $id = (new ContactRepository($db))->create(new ContactData(
            null,
            'Chosen',
            'chosen',
            self::$channelId,
            [],
            externalUuid: $uuid
        ));

        $this->assertSame($uuid, (string) (new ContactRepository($db))->find($id)->external_uuid);
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheGroupMemberships(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $groupRepository = new ContactGroupRepository($db);
        $groupId = $groupRepository->create(new ContactGroupData(null, 'Group'));

        $id = $repository->create(new ContactData(null, 'Member', 'member', self::$channelId, [], [$groupId]));

        $this->assertSame([$groupId], $this->groupsOf($db, $id), 'The contact was not linked to the group');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdatePerformsADifferentialGroupUpdate(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $groupRepository = new ContactGroupRepository($db);
        $dropped = $groupRepository->create(new ContactGroupData(null, 'Dropped'));
        $retained = $groupRepository->create(new ContactGroupData(null, 'Retained'));
        $added = $groupRepository->create(new ContactGroupData(null, 'Added'));

        $id = $repository->create(
            new ContactData(null, 'Member', 'member', self::$channelId, [], [$dropped, $retained])
        );

        $repository->update(new ContactData(
            $id,
            'Member',
            'member',
            self::$channelId,
            [],
            [$retained, $added]
        ));

        $this->assertSame(
            [$retained, $added],
            $this->groupsOf($db, $id),
            'The membership set was not synced (kept, added, removed)'
        );

        // A membership that is re-established must revive the soft-deleted link instead of inserting a
        // second one, as (contactgroup_id, contact_id) is the primary key of contactgroup_member
        $repository->update(new ContactData(
            $id,
            'Member',
            'member',
            self::$channelId,
            [],
            [$dropped, $retained, $added]
        ));

        $this->assertSame(
            [$dropped, $retained, $added],
            $this->groupsOf($db, $id),
            'A dropped membership was not revived'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateLeavesGroupMembershipsUntouchedIfNotGiven(Connection $db): void
    {
        // The contact form doesn't manage memberships, hence NULL must not be mistaken for "no groups"
        $repository = new ContactRepository($db);
        $groupId = (new ContactGroupRepository($db))->create(new ContactGroupData(null, 'Group'));
        $id = $repository->create(new ContactData(null, 'Member', 'member', self::$channelId, [], [$groupId]));

        $repository->update(new ContactData($id, 'Member Renamed', 'member', self::$channelId, []));

        $this->assertSame([$groupId], $this->groupsOf($db, $id), 'The memberships should have been left alone');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdatePerformsADifferentialAddressUpdate(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(
            null,
            'Jane',
            'jane',
            self::$channelId,
            ['email' => 'old@example.com', 'rocketchat' => '@jane']
        ));

        // email changed, rocketchat dropped, sms added
        $repository->update(new ContactData(
            $id,
            'Jane Doe',
            'jane',
            self::$channelId,
            ['email' => 'new@example.com', 'sms' => '12345']
        ));

        $contact = $repository->find($id);
        $this->assertSame('Jane Doe', $contact->full_name);

        $this->assertSame(
            ['email' => 'new@example.com', 'sms' => '12345'],
            $this->addressesByType($db, $id),
            'The address set was not synced (kept-and-changed, added, removed)'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheContactHasNoId(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ContactRepository($db))
            ->update(new ContactData(null, 'John Doe', 'jdoe', self::$channelId, []));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheContactDoesNotExist(Connection $db): void
    {
        $this->expectException(RuntimeException::class);

        (new ContactRepository($db))
            ->update(new ContactData(999, 'John Doe', 'jdoe', self::$channelId, []));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheContactAndItsAddresses(Connection $db): void
    {
        // A unique username, since the schema is shared across tests and usernames are unique
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(
            null,
            'John',
            'doomed',
            self::$channelId,
            ['email' => 'a@example.com']
        ));

        $repository->delete($id);

        // The repository hides deleted contacts
        $this->assertNull($repository->find($id), 'A deleted contact must not be found anymore');

        // But it's only soft-deleted: the row still exists, flagged deleted
        $contact = $this->loadRawEntity($db, $id, Contact::class);
        $this->assertNotNull($contact, 'The contact row should still exist');
        $this->assertSame('y', $contact->deleted, 'The contact should be soft-deleted, not removed');

        // Its addresses are soft-deleted too
        $this->assertSame([], $this->addressesByType($db, $id), 'The contact\'s addresses should be soft-deleted');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheContactDoesNotExist(Connection $db): void
    {
        $this->expectException(RuntimeException::class);

        (new ContactRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteNullsTheUsernameAndExternalUUID(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(null, 'Named', 'named', self::$channelId, []));

        $repository->delete($id);

        // The unique username must be nulled on deletion so the same web user can be re-added later
        $contact = $this->loadRawEntity($db, $id, Contact::class);
        $this->assertSame('y', $contact->deleted);
        $this->assertNull($contact->username, 'The unique username must be nulled on deletion so it can be reused');
        $this->assertNull($contact->external_uuid, 'The contact\'s external_uuid should be cleared on deletion');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesGroupMemberships(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $id = $repository->create(new ContactData(null, 'Grouped', 'grouped', self::$channelId, []));

        // Put the contact into a group
        $groupId = (new ContactGroupRepository($db))->create(new ContactGroupData(null, 'Group', [$id]));

        $repository->delete($id);

        // The membership must be soft-deleted, otherwise the deleted contact still counts as a group member
        $liveMembership = ContactgroupMember::on($db)
            ->filter(Filter::equal('contactgroup_id', $groupId))
            ->filter(Filter::equal('contact_id', $id))
            ->first();
        $this->assertNull($liveMembership, 'The contact\'s group membership must be soft-deleted on deletion');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteRemovesSolelyOwnedRotationsButKeepsSharedOnes(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $c1 = $repository->create(new ContactData(null, 'C1', 'c1', self::$channelId, []));
        $c2 = $repository->create(new ContactData(null, 'C2', 'c2', self::$channelId, []));

        $db->insert('schedule', [
            'name' => 'S', 'timezone' => 'Europe/Berlin', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);
        $scheduleId = (int) $db->lastInsertId();

        // One rotation owned solely by C1, one shared between C1 and C2
        $this->createRotation($db, $scheduleId, 1, [['contact', $c1]]);
        $this->createRotation($db, $scheduleId, 2, [['contact', $c1], ['contact', $c2]]);

        $rotations = $this->rotationsOf($db, $scheduleId);
        $soleId = $rotations[0]->id;

        $repository->delete($c1);

        $remainingRotations = $this->rotationsOf($db, $scheduleId);
        $this->assertCount(1, $remainingRotations);
        $shared = $remainingRotations[0];

        // The solely-owned rotation is gone; the shared one survives and is renumbered to close the freed slot —
        // it moves up from priority 2 to 1 now that the priority-1 rotation below it was removed
        $this->assertSame(1, $shared->priority, 'The surviving rotation must move up to close the freed priority');
        $this->assertSame(
            'y',
            $this->loadRawEntity($db, $soleId, Rotation::class)->deleted,
            'The solely-owned rotation should be soft-deleted'
        );

        // C1's membership in the shared rotation must be SOFT-deleted (row kept, flagged), not physically removed
        $c1Membership = $this->loadRawEntity($db, ['contact_id' => $c1], RotationMember::class);
        $this->assertNotNull($c1Membership, 'The membership row must still exist (soft-deleted, not hard-deleted)');
        $this->assertSame('y', $c1Membership->deleted, 'The membership must be soft-deleted');

        // C2 remains an active member of the shared rotation
        $c2Membership = RotationMember::on($db)
            ->filter(Filter::equal('rotation_id', $shared->id))
            ->filter(Filter::equal('contact_id', $c2))
            ->first();
        $this->assertNotNull($c2Membership, 'C2 should remain an active member of the shared rotation');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteRenumbersSurvivingRotationsWithoutColliding(Connection $db): void
    {
        $repository = new ContactRepository($db);
        $c1 = $repository->create(new ContactData(null, 'C1', 'c1', self::$channelId, []));
        $c2 = $repository->create(new ContactData(null, 'C2', 'c2', self::$channelId, []));

        $db->insert('schedule', [
            'name' => 'S', 'timezone' => 'Europe/Berlin', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);
        $scheduleId = (int) $db->lastInsertId();

        // A sole-owned rotation BELOW two rotations shared with C2. Deleting C1 drops the sole one (which renumbers
        // the rotations above it) and recreates the two shared ones for C2. The delete must visit rotations
        // highest-priority-first: visiting the sole one first would shift the shared ones down in the DB while they
        // are recreated from stale in-memory priorities, recreating one onto a slot another was just shifted into —
        // a unique-constraint violation on (schedule_id, priority, first_handoff).
        $this->createRotation($db, $scheduleId, 0, [['contact', $c1]]);
        $this->createRotation($db, $scheduleId, 1, [['contact', $c1], ['contact', $c2]]);
        $this->createRotation($db, $scheduleId, 2, [['contact', $c1], ['contact', $c2]]);

        $repository->delete($c1);

        // The sole rotation is gone; the two shared ones survive with contiguous priorities and only C2 as member
        $remaining = $this->rotationsOf($db, $scheduleId);
        $this->assertSame([0, 1], array_map(fn ($r) => (int) $r->priority, $remaining), 'Survivors must be contiguous');
        foreach ($remaining as $rotation) {
            $this->assertSame(
                [$c2],
                $this->rotationMemberContactIds($db, (int) $rotation->id),
                'Only C2 should remain a member of each surviving rotation'
            );
        }
    }

    /**
     * Get the active member contact ids of the given rotation, sorted
     *
     * @param Connection $db
     * @param int $rotationId
     *
     * @return list<int>
     */
    private function rotationMemberContactIds(Connection $db, int $rotationId): array
    {
        $ids = [];
        $query = RotationMember::on($db)
            ->filter(Filter::equal('rotation_id', $rotationId));
        foreach ($query as $member) {
            $ids[] = (int) $member->contact_id;
        }

        sort($ids);

        return $ids;
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
                ->orderBy('priority')
        );
    }

    /**
     * Create a simple 24/7 rotation with the given members
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
}
