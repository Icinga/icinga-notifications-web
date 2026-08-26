<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\Timeperiod;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Module\Notifications\Repository\RotationRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see RotationRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. A single contact (to be used as a rotation member) is seeded per test
 * in {@see self::initializeNotificationsDb()} and its id captured into {@see self::$contactId} (the id can't be
 * assumed as rolled-back transactions still advance the auto-increment).
 *
 * Rotations are unique by `(schedule_id, priority, first_handoff)`, so each test creates its own throwaway schedule
 * and puts its rotations there.
 */
#[TransactionIsolation]
class RotationRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int Id of the contact seeded per test, used as a rotation member */
    private static int $contactId;

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
        $channelId = (int) $db->lastInsertId();
        $db->insert('contact', [
            'full_name' => 'Test', 'username' => 'test', 'default_channel_id' => $channelId,
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000a1'),
            'changed_at' => $now
        ]);
        self::$contactId = (int) $db->lastInsertId();
    }

    /**
     * Create a throwaway schedule and return its id
     *
     * @param Connection $db
     *
     * @return int
     */
    private function createSchedule(Connection $db): int
    {
        $db->insert('schedule', [
            'name' => 'Schedule', 'timezone' => 'Europe/Berlin', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Build a simple 24/7 daily rotation with a single contact member
     *
     * @param int $scheduleId
     * @param int $priority
     * @param string $name
     *
     * @return RotationData
     */
    private function rotationData(int $scheduleId, int $priority, string $name): RotationData
    {
        return new RotationData(
            null,
            $scheduleId,
            $priority,
            $name,
            '24-7',
            ['interval' => 1, 'frequency' => 'd', 'at' => '09:00'],
            [['contact', self::$contactId]],
            new DateTimeImmutable('2026-01-05 00:00', new DateTimeZone('Europe/Berlin'))
        );
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

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheRotationDoesNotExist(Connection $db): void
    {
        $this->assertNull((new RotationRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheRotationWithAllRelatedRecords(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        (new RotationRepository($db))->create($this->rotationData($scheduleId, 1, 'Create Rotation'));

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertCount(1, $rotations, 'The rotation was not created');
        $rotation = $rotations[0];

        $this->assertSame('Create Rotation', $rotation->name);
        $this->assertSame('24-7', $rotation->mode);
        $this->assertSame(1, (int) $rotation->priority);
        $this->assertSame('2026-01-05', $rotation->first_handoff);
        $this->assertSame(['interval' => 1, 'frequency' => 'd', 'at' => '09:00'], $rotation->options);

        // The member
        $members = iterator_to_array(
            RotationMember::on($db)
                ->filter(Filter::equal('rotation_id', $rotation->id))
        );
        $this->assertCount(1, $members);
        $this->assertEquals(self::$contactId, $members[0]->contact_id);
        $this->assertSame(0, (int) $members[0]->position);

        // The timeperiod and at least one entry
        $timeperiod = Timeperiod::on($db)
            ->filter(Filter::equal('owned_by_rotation_id', $rotation->id))
            ->first();
        $this->assertNotNull($timeperiod, 'The rotation\'s timeperiod was not created');

        $entries = iterator_to_array(
            TimeperiodEntry::on($db)
                ->filter(Filter::equal('timeperiod_id', $timeperiod->id))
        );
        $this->assertNotEmpty($entries, 'The timeperiod should have at least one entry');

        $this->assertSame($timeperiod->id, $entries[0]->timeperiod_id, 'Incorrect timeperiod ID');
        $this->assertSame($members[0]->id, $entries[0]->rotation_member_id, 'Incorrect member ID');
        $this->assertSame(1767600000, $entries[0]->start_time->getTimestamp(), 'Incorrect start time');
        $this->assertSame(1767686400, $entries[0]->end_time->getTimestamp(), 'Incorrect end time');
        $this->assertNull($entries[0]->until_time, 'Incorrect until time');
        $this->assertSame('Europe/Berlin', $entries[0]->timezone, 'Incorrect timezone');
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsTheCreatedRotation(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Find Rotation'));

        $id = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        $rotation = $repository->find($id);
        $this->assertNotNull($rotation);
        $this->assertSame('Find Rotation', $rotation->name);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateRecreatesTheRotation(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Before Update'));

        $id = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        $data = $this->rotationData($scheduleId, 1, 'After Update');
        $repository->update(new RotationData(
            $id,
            $data->scheduleId,
            $data->priority,
            $data->name,
            $data->mode,
            $data->options,
            $data->members,
            $data->firstHandoff
        ));

        // The rotation is torn down and recreated, so the schedule now has a single, renamed rotation
        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertCount(1, $rotations);
        $this->assertSame('After Update', $rotations[0]->name);
        $this->assertNotEquals($id, (int) $rotations[0]->id, 'The old version should have been replaced');

        // The member
        $members = iterator_to_array(
            RotationMember::on($db)
                ->filter(Filter::equal('rotation_id', $rotations[0]->id))
        );
        $this->assertCount(1, $members);
        $this->assertEquals(self::$contactId, $members[0]->contact_id);
        $this->assertSame(0, (int) $members[0]->position);

        // The timeperiod and at least one entry
        $timeperiod = Timeperiod::on($db)
            ->filter(Filter::equal('owned_by_rotation_id', $rotations[0]->id))
            ->first();
        $this->assertNotNull($timeperiod, 'The rotation\'s timeperiod was not created');

        $entries = iterator_to_array(
            TimeperiodEntry::on($db)
                ->filter(Filter::equal('timeperiod_id', $timeperiod->id))
        );
        $this->assertNotEmpty($entries, 'The timeperiod should have at least one entry');

        $this->assertSame($timeperiod->id, $entries[0]->timeperiod_id, 'Incorrect timeperiod ID');
        $this->assertSame($members[0]->id, $entries[0]->rotation_member_id, 'Incorrect member ID');
        $this->assertSame(1767600000, $entries[0]->start_time->getTimestamp(), 'Incorrect start time');
        $this->assertSame(1767686400, $entries[0]->end_time->getTimestamp(), 'Incorrect end time');
        $this->assertNull($entries[0]->until_time, 'Incorrect until time');
        $this->assertSame('Europe/Berlin', $entries[0]->timezone, 'Incorrect timezone');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheRotationAndItsRecords(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Delete Rotation'));

        $id = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        $repository->delete($id);

        $this->assertCount(0, $this->rotationsOf($db, $scheduleId), 'The rotation should no longer be listed');

        // The row still exists but is soft-deleted with its priority freed
        $rotation = $this->loadRawEntity($db, $id, Rotation::class);
        $this->assertNotNull($rotation);
        $this->assertSame('y', $rotation->deleted, 'The rotation should be soft-deleted');
        $this->assertNull($rotation->priority, 'The freed priority should be nulled');

        // Its timeperiod is soft-deleted too
        $timeperiod = $this->loadRawEntity($db, ['owned_by_rotation_id' => $id], Timeperiod::class);
        $this->assertSame('y', $timeperiod->deleted, 'The rotation\'s timeperiod should be soft-deleted');

        $timeperiodEntry = $this->loadRawEntity($db, ['timeperiod_id' => $timeperiod->id], TimeperiodEntry::class);
        $this->assertSame('y', $timeperiodEntry->deleted, 'The timeperiod entries should be soft-deleted');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheRotationDoesNotExist(Connection $db): void
    {
        $this->expectException(RuntimeException::class);

        (new RotationRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testMoveShiftsSiblingPriorities(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Rotation A'));
        $repository->create($this->rotationData($scheduleId, 2, 'Rotation B'));

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(['Rotation A', 'Rotation B'], array_map(fn ($r) => $r->name, $rotations));
        $rotationBId = (int) $rotations[1]->id;

        // Move B to the top; A must shift down to make room
        $repository->move($rotationBId, 1);

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(1, (int) $rotations[0]->priority);
        $this->assertSame('Rotation B', $rotations[0]->name);
        $this->assertSame(2, (int) $rotations[1]->priority);
        $this->assertSame('Rotation A', $rotations[1]->name);
    }

    #[DataProvider('sharedDatabases')]
    public function testMoveThrowsWhenTheRotationDoesNotExist(Connection $db): void
    {
        $this->expectException(RuntimeException::class);

        (new RotationRepository($db))->move(999, 1);
    }

    #[DataProvider('sharedDatabases')]
    public function testWipeRemovesTheRotation(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Wipe Rotation'));

        $repository->wipe($this->rotationData($scheduleId, 1, 'Wipe Rotation'));

        $this->assertCount(0, $this->rotationsOf($db, $scheduleId), 'All versions of the rotation should be gone');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdatingANonLastRotationKeepsSiblingPriorities(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Rotation A'));
        $repository->create($this->rotationData($scheduleId, 2, 'Rotation B'));

        $aId = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        // Editing the first (non-last) rotation must neither collide nor disturb sibling B's priority. The rotation
        // is torn down and recreated at its own priority, so the tear-down must not renumber the siblings.
        $data = $this->rotationData($scheduleId, 1, 'Rotation A (edited)');
        $repository->update(new RotationData(
            $aId,
            $data->scheduleId,
            $data->priority,
            $data->name,
            $data->mode,
            $data->options,
            $data->members,
            $data->firstHandoff
        ));

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(
            ['Rotation A (edited)', 'Rotation B'],
            array_map(fn ($r) => $r->name, $rotations),
            'Both rotations should remain, in order'
        );
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r->priority, $rotations), 'Priorities must be intact');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreatePrependingAtPriorityZeroShiftsSiblings(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 0, 'Rotation A'));
        $repository->create($this->rotationData($scheduleId, 1, 'Rotation B'));

        // Prepend a new rotation at priority 0; the existing ones must shift up by one
        $repository->create($this->rotationData($scheduleId, 0, 'Rotation C'));

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(
            ['Rotation C', 'Rotation A', 'Rotation B'],
            array_map(fn ($r) => $r->name, $rotations)
        );
        $this->assertSame([0, 1, 2], array_map(fn ($r) => (int) $r->priority, $rotations));
    }

    #[DataProvider('sharedDatabases')]
    public function testMoveDownShiftsSiblingsUp(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Rotation A'));
        $repository->create($this->rotationData($scheduleId, 2, 'Rotation B'));
        $repository->create($this->rotationData($scheduleId, 3, 'Rotation C'));

        $aId = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        // Move A from the top to the bottom; B and C must shift up to fill the gap
        $repository->move($aId, 3);

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(
            ['Rotation B', 'Rotation C', 'Rotation A'],
            array_map(fn ($r) => $r->name, $rotations)
        );
        $this->assertSame([1, 2, 3], array_map(fn ($r) => (int) $r->priority, $rotations));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteInTheMiddleRenumbersLaterSiblings(Connection $db): void
    {
        $scheduleId = $this->createSchedule($db);
        $repository = new RotationRepository($db);
        $repository->create($this->rotationData($scheduleId, 1, 'Rotation A'));
        $repository->create($this->rotationData($scheduleId, 2, 'Rotation B'));
        $repository->create($this->rotationData($scheduleId, 3, 'Rotation C'));

        $bId = (int) $this->rotationsOf($db, $scheduleId)[1]->id;

        $repository->delete($bId);

        $rotations = $this->rotationsOf($db, $scheduleId);
        $this->assertSame(['Rotation A', 'Rotation C'], array_map(fn ($r) => $r->name, $rotations));
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r->priority, $rotations), 'Later siblings shift up');
    }
}
