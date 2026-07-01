<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use ArrayIterator;
use DateTime;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Repository\ScheduleRepository;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use IteratorAggregate;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ScheduleRepository}.
 *
 * Like {@see RotationRepositoryTest}, these tests don't talk to a real database. A {@see Connection} mock is used and
 * the statements the repository is expected to issue are anticipated and verified. The ORM queries the repository
 * builds internally are served by returning real {@see PDOStatement}s (backed by an in-memory SQLite database) whose
 * rows the ORM hydrates into models.
 *
 * What these tests do not cover:
 * - The actual interaction with a production database, which is mocked.
 * - The rendered SQL of the issued statements (the connection is mocked before rendering happens).
 * - The deletion/duplication of the schedule's rotations, which is delegated to (and covered by)
 *   {@see RotationRepositoryTest}.
 */
class ScheduleRepositoryTest extends TestCase
{
    /**
     * Build a real PDOStatement yielding the given rows
     *
     * See {@see RotationRepositoryTest::selectResult()} for the rationale.
     *
     * @param list<array<string, mixed>> $rows All rows must share the same keys, which become the result's columns
     *
     * @return PDOStatement
     */
    private function selectResult(array $rows): PDOStatement
    {
        $columns = empty($rows) ? ['id'] : array_keys($rows[0]);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $pdo->exec('CREATE TABLE result (' . implode(', ', array_map(fn ($c) => '"' . $c . '"', $columns)) . ')');

        if (! empty($rows)) {
            $insert = $pdo->prepare(
                'INSERT INTO result VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );

            foreach ($rows as $row) {
                $insert->execute(array_values($row));
            }
        }

        return $pdo->query('SELECT * FROM result');
    }

    /**
     * Covers fetching a schedule, anticipating the `select` it issues and providing a row the ORM hydrates into the
     * returned model.
     *
     * @return void
     */
    public function testFindHydratesTheSchedule(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn($this->selectResult([
                [
                    'id'        => 5,
                    'name'      => 'My Schedule',
                    'timezone'  => 'Europe/Berlin',
                    'deleted'   => 'n'
                ]
            ]));

        $schedule = (new ScheduleRepository($databaseMock))->find(5);

        $this->assertNotNull($schedule, 'find() did not return the schedule');
        $this->assertEquals(5, $schedule->id);
        $this->assertSame('My Schedule', $schedule->name);
        $this->assertSame('Europe/Berlin', $schedule->timezone);
        $this->assertFalse($schedule->deleted, 'The deleted flag should be cast to a bool');
    }

    /**
     * Covers fetching a non-existent schedule, which is expected to yield null.
     *
     * @return void
     */
    public function testFindReturnsNullIfTheScheduleDoesNotExist(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $this->assertNull((new ScheduleRepository($databaseMock))->find(404));
    }

    /**
     * Covers creating a schedule: it's inserted and its generated id is set on the model.
     *
     * @return void
     */
    public function testCreateInsertsScheduleAndAssignsTheId(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $schedule = (new Schedule())->setProperties([
            'name'      => 'New Schedule',
            'timezone'  => 'Europe/Vienna'
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('update');

        $databaseMock->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start) {
                $this->assertSame('schedule', $table);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(['name' => 'New Schedule', 'timezone' => 'Europe/Vienna'], $data);

                return $this->createStub(PDOStatement::class);
            });

        $databaseMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('77');

        (new ScheduleRepository($databaseMock))->create($schedule);

        $this->assertSame('77', $schedule->id, 'The generated id was not assigned to the schedule');
    }

    /**
     * Covers updating a schedule.
     *
     * @return void
     */
    public function testUpdateUpdatesTheSchedule(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $schedule = (new Schedule())->setProperties([
            'id'        => 5,
            'name'      => 'Renamed Schedule',
            'timezone'  => 'Europe/Vienna'
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('insert');

        $databaseMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('schedule', $table);
                $this->assertSame(['id = ?' => 5], $where);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(['name' => 'Renamed Schedule', 'timezone' => 'Europe/Vienna'], $data);

                return $this->createStub(PDOStatement::class);
            });

        (new ScheduleRepository($databaseMock))->update($schedule);
    }

    /**
     * Covers deleting a schedule that has no rotations and whose recipient references aren't shared by other
     * recipients, so the schedule and its recipient references are simply marked as deleted.
     *
     * @return void
     */
    public function testDeleteMarksScheduleAndRecipientReferencesDeleted(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $schedule = (new Schedule())->setProperties(['id' => 5]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        // The rotations query, here without any results
        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $databaseMock->expects($this->never())
            ->method('insert');

        // The single fetchCol determines no escalation references this schedule, so no escalations are touched
        $databaseMock->expects($this->once())
            ->method('fetchCol')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn([]);

        $tables = [];
        $databaseMock->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$tables) {
                $tables[] = $table;

                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(['deleted' => 'y'], $data);

                if ($table === 'rule_escalation_recipient') {
                    $this->assertSame(['schedule_id = ?' => 5], $where);
                } elseif ($table === 'schedule') {
                    $this->assertSame(['id = ?' => 5], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new ScheduleRepository($databaseMock))->delete($schedule);

        $this->assertSame(['rule_escalation_recipient', 'schedule'], $tables);
    }

    /**
     * Covers deleting a schedule whose recipient references include escalations not referenced by any other
     * recipient. Those escalations are expected to be marked as deleted as well.
     *
     * @return void
     */
    public function testDeleteAlsoRemovesEscalationsLeftWithoutRecipients(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $schedule = (new Schedule())->setProperties(['id' => 5]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $databaseMock->expects($this->never())
            ->method('insert');

        // First the escalations referenced by the schedule's recipients (5 and 6), then those among them that still
        // have other recipients (only 6). Escalation 5 is thus left without recipients and must be removed.
        $databaseMock->expects($this->exactly(2))
            ->method('fetchCol')
            ->willReturnOnConsecutiveCalls([5, 6], [6]);

        $updates = [];
        $databaseMock->expects($this->exactly(3))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$updates) {
                $updates[$table] = ['data' => $data, 'where' => $where];

                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'rule_escalation_recipient') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['schedule_id = ?' => 5], $where);
                } elseif ($table === 'rule_escalation') {
                    $this->assertSame(['deleted' => 'y', 'position' => null], $data);
                    $this->assertSame(['id IN (?)' => [5]], $where);
                } elseif ($table === 'schedule') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['id = ?' => 5], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new ScheduleRepository($databaseMock))->delete($schedule);

        $this->assertSame(
            ['rule_escalation_recipient', 'rule_escalation', 'schedule'],
            array_keys($updates),
            'The escalation left without recipients was not removed in the expected order'
        );
    }

    /**
     * Covers duplicating a schedule (without rotations): a new schedule is inserted with the given name and timezone
     * and its id is returned.
     *
     * @return void
     */
    public function testDuplicateInsertsScheduleAndReturnsTheNewId(): void
    {
        $start = (int) (new DateTime())->format('Uv');
        $rotationPropertyMock = $this->createMock(IteratorAggregate::class);
        $rotationPropertyMock
            ->expects($this->once())
            ->method('getIterator')
            ->willReturn(new ArrayIterator([]));

        $schedule = (new Schedule())->setProperties([
            'id'        => 5,
            'name'      => 'Copy',
            'timezone'  => 'America/New_York',
            'rotation'  => $rotationPropertyMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('update');

        $databaseMock->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start) {
                $this->assertSame('schedule', $table);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(['name' => 'Copy', 'timezone' => 'America/New_York'], $data);

                return $this->createStub(PDOStatement::class);
            });

        $databaseMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('88');

        (new ScheduleRepository($databaseMock))->duplicate($schedule);

        $this->assertSame(88, $schedule->id);
    }
}
