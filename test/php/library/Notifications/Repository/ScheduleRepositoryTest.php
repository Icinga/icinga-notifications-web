<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Form\Data\EscalationRecipient;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Form\Data\Schedule as ScheduleData;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Module\Notifications\Repository\EscalationRepository;
use Icinga\Module\Notifications\Repository\RotationRepository;
use Icinga\Module\Notifications\Repository\ScheduleRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see ScheduleRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. A contact and a contact group are seeded per test in
 * {@see self::initializeNotificationsDb()} (their ids captured into {@see self::$contactId} /
 * {@see self::$contactgroupId}) to be used as rotation members and escalation recipients.
 */
#[TransactionIsolation]
class ScheduleRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int Id of the contact seeded per test */
    private static int $contactId;

    /** @var int Id of the contact group seeded per test */
    private static int $contactgroupId;

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
        $db->insert('contactgroup', [
            'name' => 'Test Group',
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000b1'),
            'changed_at' => $now
        ]);
        self::$contactgroupId = (int) $db->lastInsertId();
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
     * Get the rotation's non-deleted members as `[type, id]` pairs, ordered by position
     *
     * @param Connection $db
     * @param int $rotationId
     *
     * @return array<array{string, int}>
     */
    private function membersOf(Connection $db, int $rotationId): array
    {
        $members = [];
        $query = RotationMember::on($db)
            ->filter(Filter::equal('rotation_id', $rotationId))
            ->orderBy('position');
        foreach ($query as $member) {
            $members[] = $member->contact_id !== null
                ? ['contact', (int) $member->contact_id]
                : ['contact_group', (int) $member->contactgroup_id];
        }

        return $members;
    }

    /**
     * Create a simple 24/7 rotation in the given schedule with the given members
     *
     * @param Connection $db
     * @param int $scheduleId
     * @param int $priority
     * @param string $name
     * @param array<array{string, int}> $members
     *
     * @return void
     */
    private function createRotation(Connection $db, int $scheduleId, int $priority, string $name, array $members): void
    {
        (new RotationRepository($db))->create(new RotationData(
            null,
            $scheduleId,
            $priority,
            $name,
            '24-7',
            ['interval' => 1, 'frequency' => 'd', 'at' => '09:00'],
            $members,
            new DateTimeImmutable('2026-01-05 00:00', new DateTimeZone('America/New_York'))
        ));
    }

    /**
     * Create a rule with a single escalation that targets only the given schedule and return the escalation's id
     *
     * @param Connection $db
     * @param int $scheduleId
     *
     * @return int
     */
    private function createEscalationTargetingSchedule(Connection $db, int $scheduleId): int
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
            [new EscalationRecipient(null, 'schedule', $scheduleId, null)],
            $ruleId
        ));
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheScheduleDoesNotExist(Connection $db): void
    {
        $this->assertNull((new ScheduleRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheScheduleAndReturnsItsId(Connection $db): void
    {
        $repository = new ScheduleRepository($db);

        $id = $repository->create(new ScheduleData(null, 'My Schedule', 'Europe/Berlin'));

        $schedule = $repository->find($id);
        $this->assertNotNull($schedule, 'The created schedule was not found');
        $this->assertSame('My Schedule', $schedule->name);
        $this->assertSame('Europe/Berlin', $schedule->timezone);
        $this->assertFalse($schedule->deleted);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesNameAndTimezone(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $id = $repository->create(new ScheduleData(null, 'Old Name', 'Europe/Berlin'));

        $repository->update(new ScheduleData($id, 'New Name', 'Europe/Vienna'));

        $schedule = $repository->find($id);
        $this->assertSame('New Name', $schedule->name);
        $this->assertSame('Europe/Vienna', $schedule->timezone);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateWithNewTimezoneRegeneratesRotations(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $id = $repository->create(new ScheduleData(null, 'Schedule', 'America/New_York'));

        // Prepend at priority 0 so 'Contact Rotation' ends up at priority 1 (mirrors the duplicate test's setup)
        $this->createRotation($db, $id, 0, 'Contact Rotation', [
            ['contact', self::$contactId],
            ['contact_group', self::$contactgroupId],
        ]);
        $this->createRotation($db, $id, 0, 'Group Rotation', [['contact_group', self::$contactgroupId]]);

        // Remove the contactgroup membership of the first rotation to verify it is not revived by the update
        $rotation = Rotation::on($db)->filter(Filter::equal('priority', 1))->first();
        (new RotationRepository($db))->update(new RotationData(
            $rotation->id,
            $rotation->schedule_id,
            $rotation->priority,
            $rotation->name,
            $rotation->mode,
            $rotation->options,
            [['contact', self::$contactId]],
            DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $rotation->first_handoff,
                new DateTimeZone('America/New_York')
            )
        ));

        // Change only the timezone; the rotations must be regenerated for it, exactly as duplicate() does
        $repository->update(new ScheduleData($id, 'Schedule', 'Europe/Berlin'));

        $this->assertSame('Europe/Berlin', $repository->find($id)->timezone);

        $rotations = $this->rotationsOf($db, $id);
        $this->assertCount(2, $rotations, 'Both rotations must survive the timezone change');

        // Priorities, order and members are preserved
        $this->assertSame([0, 1], array_map(fn ($r) => (int) $r->priority, $rotations), 'Priorities must be kept');
        $this->assertSame(['Group Rotation', 'Contact Rotation'], array_map(fn ($r) => $r->name, $rotations));
        $this->assertSame([['contact_group', self::$contactgroupId]], $this->membersOf($db, (int) $rotations[0]->id));
        $this->assertSame([['contact', self::$contactId]], $this->membersOf($db, (int) $rotations[1]->id));

        // Their regenerated timeperiod entries carry the new timezone, not the original's
        foreach ($rotations as $rotation) {
            $entries = iterator_to_array(
                TimeperiodEntry::on($db)
                    ->filter(Filter::equal('timeperiod.owned_by_rotation_id', $rotation->id))
            );
            $this->assertNotEmpty($entries, 'A regenerated rotation should have timeperiod entries');
            foreach ($entries as $entry) {
                $this->assertSame(
                    'Europe/Berlin',
                    $entry->timezone,
                    'Regenerated entries must use the new timezone, not the original\'s'
                );
                $this->assertSame(
                    '09:00',
                    $entry->start_time->setTimezone(new DateTimeZone($entry->timezone))->format('H:i'),
                    'Regenerated entries must start at the original\'s time'
                );
            }
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateWithoutATimezoneChangeLeavesRotationsUntouched(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $id = $repository->create(new ScheduleData(null, 'Old Name', 'Europe/Berlin'));
        $this->createRotation($db, $id, 0, 'R', [['contact', self::$contactId]]);

        $rotationId = (int) $this->rotationsOf($db, $id)[0]->id;

        // Only the name changes; with the timezone unchanged the rotations must not be regenerated
        $repository->update(new ScheduleData($id, 'New Name', 'Europe/Berlin'));

        $rotations = $this->rotationsOf($db, $id);
        $this->assertCount(1, $rotations);
        $this->assertSame(
            $rotationId,
            (int) $rotations[0]->id,
            'A rotation must be left untouched when the timezone is unchanged'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheScheduleHasNoId(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScheduleRepository($db))->update(new ScheduleData(null, 'My Schedule', 'Europe/Berlin'));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheScheduleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScheduleRepository($db))->update(new ScheduleData(999, 'My Schedule', 'Europe/Berlin'));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheSchedule(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $id = $repository->create(new ScheduleData(null, 'Doomed', 'Europe/Berlin'));

        $repository->delete($id);

        // The repository hides deleted schedules
        $this->assertNull($repository->find($id), 'A deleted schedule must not be found anymore');

        // But it's only soft-deleted: the row still exists, flagged deleted
        $schedule = $this->loadRawEntity($db, $id, Schedule::class);
        $this->assertNotNull($schedule, 'The schedule row should still exist');
        $this->assertSame('y', $schedule->deleted, 'The schedule should be soft-deleted, not removed');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheScheduleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScheduleRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testDuplicateThrowsWhenTheOriginalDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScheduleRepository($db))->duplicate(new ScheduleData(999, 'Copy', 'Europe/Berlin'));
    }

    #[DataProvider('sharedDatabases')]
    public function testDuplicateAlsoCopiesRotationsWithMembersAndTimezone(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $originalId = $repository->create(new ScheduleData(null, 'Original', 'America/New_York'));

        // Build the original so the priority-0 rotation is the one created LAST (highest id) — exactly what happens
        // when a rotation is prepended in the UI. This is what makes duplication's iteration order matter: without an
        // explicit ORDER BY, the priority-1 rotation would be copied first and the priority-0 create()'s sibling
        // shift would then push the already-copied one aside, corrupting the copied priorities.
        $this->createRotation($db, $originalId, 0, 'Contact Rotation', [
            ['contact', self::$contactId],
            ['contact_group', self::$contactgroupId]
        ]);
        // Prepending at priority 0 shifts 'Contact Rotation' to priority 1
        $this->createRotation($db, $originalId, 0, 'Group Rotation', [['contact_group', self::$contactgroupId]]);

        // Remove the contactgroup membership of the first rotation to verify it is not revived by the duplication
        $rotation = Rotation::on($db)->filter(Filter::equal('priority', 1))->first();
        (new RotationRepository($db))->update(new RotationData(
            $rotation->id,
            $rotation->schedule_id,
            $rotation->priority,
            $rotation->name,
            $rotation->mode,
            $rotation->options,
            [['contact', self::$contactId]],
            DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $rotation->first_handoff,
                new DateTimeZone('America/New_York')
            )
        ));

        $copyId = $repository->duplicate(new ScheduleData($originalId, 'Copy', 'Europe/Berlin'));
        $this->assertNotSame($originalId, $copyId);

        $copyRotations = $this->rotationsOf($db, $copyId);
        $this->assertCount(2, $copyRotations, 'Both rotations should have been copied');

        // Priorities preserved and contiguous, names in order (regardless of the order the originals are visited in)
        $this->assertSame([0, 1], array_map(fn ($r) => (int) $r->priority, $copyRotations), 'Priorities must be kept');
        $this->assertSame(['Group Rotation', 'Contact Rotation'], array_map(fn ($r) => $r->name, $copyRotations));

        // The copies are independent rows, not the originals
        $originalIds = array_map(fn ($r) => (int) $r->id, $this->rotationsOf($db, $originalId));
        foreach ($copyRotations as $rotation) {
            $this->assertNotContains((int) $rotation->id, $originalIds, 'A duplicated rotation must be a new row');
        }

        // Members are copied and mapped by type
        $this->assertSame(
            [['contact_group', self::$contactgroupId]],
            $this->membersOf($db, (int) $copyRotations[0]->id)
        );
        $this->assertSame([['contact', self::$contactId]], $this->membersOf($db, (int) $copyRotations[1]->id));

        // The copied rotations' timeperiod entries carry the new schedule's timezone, not the original's
        foreach ($copyRotations as $rotation) {
            $entries = iterator_to_array(
                TimeperiodEntry::on($db)
                    ->filter(Filter::equal('timeperiod.owned_by_rotation_id', $rotation->id))
            );
            $this->assertNotEmpty($entries, 'A copied rotation should have timeperiod entries');
            foreach ($entries as $entry) {
                $this->assertSame(
                    'Europe/Berlin',
                    $entry->timezone,
                    'Duplicated entries must use the new timezone, not the original\'s'
                );
            }
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteAlsoRemovesRotationsAndDereferencesEscalations(Connection $db): void
    {
        $repository = new ScheduleRepository($db);
        $scheduleId = $repository->create(new ScheduleData(null, 'Doomed', 'Europe/Berlin'));

        $this->createRotation($db, $scheduleId, 0, 'R', [['contact', self::$contactId]]);
        $rotationId = (int) $this->rotationsOf($db, $scheduleId)[0]->id;

        $escalationId = $this->createEscalationTargetingSchedule($db, $scheduleId);

        $repository->delete($scheduleId);

        $this->assertNull($repository->find($scheduleId), 'The schedule should be deleted');
        $this->assertSame(
            'y',
            $this->loadRawEntity($db, $rotationId, Rotation::class)->deleted,
            'The schedule\'s rotation should be soft-deleted'
        );
        $this->assertNull(
            (new EscalationRepository($db))->find($escalationId),
            'An escalation solely targeting the schedule should be dereferenced/removed'
        );
    }
}
