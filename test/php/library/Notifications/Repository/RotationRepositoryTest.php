<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use ArrayIterator;
use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\Timeperiod;
use Icinga\Module\Notifications\Repository\RotationRepository;
use Icinga\Util\Json;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see RotationRepository}.
 *
 * These tests don't talk to a real database. Instead a {@see Connection} mock is used and the statements the
 * repository is expected to issue are anticipated and verified.
 *
 * The repository builds ORM queries internally (e.g. `Rotation::on($this->db)`). Since the mocked connection is what
 * those queries execute against, they can be served too: `ipl\Orm\Query` ultimately calls `Connection::select()` and
 * hydrates the returned rows into models. The mock therefore returns real {@see PDOStatement}s (backed by an in-memory
 * SQLite database) whose rows the ORM hydrates, which lets these tests verify the follow-up statements the repository
 * issues based on the returned models.
 *
 * What these tests do not cover:
 * - The actual interaction with a production database, which is mocked.
 * - The rendered SQL of the issued statements (the connection is mocked before rendering happens).
 */
class RotationRepositoryTest extends TestCase
{
    /**
     * Build a real PDOStatement yielding the given rows
     *
     * `Connection::select()` is declared to return a {@see PDOStatement} and the ORM both calls `setFetchMode()` on
     * and iterates the result. A genuine SQLite-backed statement satisfies all of that without a production database.
     *
     * @param list<array<string, mixed>> $rows All rows must share the same keys, which become the result's columns
     *
     * @return PDOStatement
     */
    private function selectResult(array $rows): PDOStatement
    {
        $columns = empty($rows) ? ['id'] : array_keys($rows[0]);

        $pdo = new PDO('sqlite::memory:');
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
     * Build a simple 24/7 rotation with a single member
     *
     * The rotation hands off daily at midnight, starting on 2026-01-01. A fresh member iterator is returned on each
     * call since the repository consumes it via `iterator_to_array()`.
     *
     * @param int $priority The rotation's priority (0 triggers the prepend handling)
     *
     * @return Rotation
     */
    private function createRotation(int $priority = 1): Rotation
    {
        return (new Rotation())->setProperties([
            'id'            => 42,
            'schedule_id'   => 1,
            'priority'      => $priority,
            'name'          => 'Test Rotation',
            'mode'          => '24-7',
            'options'       => ['interval' => 1, 'frequency' => 'd', 'at' => '00:00'],
            'first_handoff' => '2026-01-01',
            'member'        => new ArrayIterator([
                (new RotationMember())->setProperties([
                    'rotation_id'       => 10,
                    'contact_id'        => 5,
                    'contactgroup_id'   => null,
                    'position'          => 0
                ])
            ])
        ]);
    }

    /**
     * Assert that the given data matches the `timeperiod_entry` the test rotation is expected to produce
     *
     * @param array<string, mixed> $data The data passed to `Connection::insert('timeperiod_entry', ...)`
     */
    private function assertTimeperiodEntry(array $data): void
    {
        // The repository derives the entry's times from the very same DateTime construction, so replaying it here
        // yields identical values regardless of the process' default timezone.
        $firstHandoff = DateTime::createFromFormat('Y-m-d H:i', '2026-01-01 00:00');

        $this->assertSame(20, (int) $data['timeperiod_id'], 'Entry is not linked to the inserted timeperiod');
        $this->assertSame(30, (int) $data['rotation_member_id'], 'Entry is not linked to the inserted member');
        $this->assertEqualsWithDelta($firstHandoff->format('U.u') * 1000.0, $data['start_time'], 0.001);
        $this->assertEqualsWithDelta(
            (clone $firstHandoff)->add(new DateInterval('P1D'))->format('U.u') * 1000.0,
            $data['end_time'],
            0.001
        );
        $this->assertNull($data['until_time'], 'A 24/7 rotation repeats indefinitely and must not have an until time');
        $this->assertSame($firstHandoff->getTimezone()->getName(), $data['timezone']);
        $this->assertStringContainsString('FREQ=DAILY', $data['rrule'], 'The recurrence rule is not daily');
        $this->assertStringContainsString('INTERVAL=1', $data['rrule'], 'The recurrence rule has the wrong interval');
    }

    /**
     * Build a callback verifying the four inserts a creation of the test rotation is expected to issue
     *
     * @param int $start The reference timestamp (ms) captured before the operation, used to verify `changed_at`
     * @param int $expectedPriority The priority the inserted rotation is expected to have
     *
     * @return callable
     */
    private function creationInsertCallback(int $start, int $expectedPriority = 1): callable
    {
        return function ($table, $data) use ($start, $expectedPriority) {
            $this->assertArrayHasKey('changed_at', $data, sprintf('Insert into %s has no changed_at', $table));
            $this->assertGreaterThanOrEqual($start, $data['changed_at']);
            $changedAt = $data['changed_at'];
            unset($data['changed_at']);

            if ($table === 'rotation') {
                // actual_handoff is derived from the current time and loses sub-second precision, so it may be
                // marginally lower than $start. It must still be in the same ballpark though.
                $this->assertArrayHasKey('actual_handoff', $data);
                $this->assertGreaterThanOrEqual($start - 1000, $data['actual_handoff']);
                unset($data['actual_handoff']);

                $this->assertEquals(
                    [
                        'schedule_id'   => 1,
                        'priority'      => $expectedPriority,
                        'name'          => 'Test Rotation',
                        'mode'          => '24-7',
                        'options'       => Json::encode(['interval' => 1, 'frequency' => 'd', 'at' => '00:00']),
                        'first_handoff' => '2026-01-01',
                        'deleted'       => 'n'
                    ],
                    $data
                );
            } elseif ($table === 'timeperiod') {
                $this->assertSame(10, (int) $data['owned_by_rotation_id'], 'Timeperiod is not owned by the rotation');
                unset($data['owned_by_rotation_id']);
                $this->assertSame([], $data, 'Timeperiod insert has unexpected columns');
            } elseif ($table === 'rotation_member') {
                $this->assertEquals(
                    [
                        'rotation_id'       => 10,
                        'contact_id'        => 5,
                        'contactgroup_id'   => null,
                        'position'          => 0,
                        'deleted'           => 'n'
                    ],
                    $data
                );
            } elseif ($table === 'timeperiod_entry') {
                $this->assertTimeperiodEntry($data + ['changed_at' => $changedAt]);
            } else {
                $this->fail(sprintf('Unexpected insert into %s', $table));
            }

            return $this->createStub(PDOStatement::class);
        };
    }

    /**
     * Covers fetching a rotation, anticipating the `select` it issues and providing a row the ORM hydrates into the
     * returned model (including the joined `schedule.timezone`).
     *
     * @return void
     */
    public function testFindHydratesTheRotation(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn($this->selectResult([
                [
                    'id'                => 42,
                    'schedule_id'       => 1,
                    'priority'          => 1,
                    'name'              => 'My Rotation',
                    'mode'              => '24-7',
                    'options'           => '{"interval":1,"frequency":"d","at":"00:00"}',
                    'first_handoff'     => '2026-01-01',
                    'deleted'           => 'n',
                    // Related columns are aliased as <base>_<relation>_<column> by the ORM
                    'rotation_schedule_timezone' => 'Europe/Berlin'
                ]
            ]));

        $rotation = (new RotationRepository($databaseMock))->find(42);

        $this->assertNotNull($rotation, 'find() did not return the rotation');
        $this->assertEquals(42, $rotation->id);
        $this->assertSame('My Rotation', $rotation->name);
        $this->assertSame('24-7', $rotation->mode);
        // The options string is decoded into an array by the model's retrieve behavior
        $this->assertSame(['interval' => 1, 'frequency' => 'd', 'at' => '00:00'], $rotation->options);
        $this->assertFalse($rotation->deleted, 'The deleted flag should be cast to a bool');
        $this->assertSame('Europe/Berlin', $rotation->schedule->timezone, 'The joined timezone was not hydrated');
    }

    /**
     * Covers fetching a non-existent rotation, which is expected to yield null.
     *
     * @return void
     */
    public function testFindReturnsNullIfTheRotationDoesNotExist(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $this->assertNull((new RotationRepository($databaseMock))->find(404));
    }

    /**
     * Covers the creation of a new rotation, verifying that the rotation, its timeperiod, its member and the
     * resulting timeperiod entry are all inserted with the expected values.
     *
     * @return void
     */
    public function testCreateInsertsRotationWithAllRelatedRecords(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        // lastInsertId() is consulted after the rotation, the timeperiod and the member inserts (in that order)
        $databaseMock->expects($this->exactly(3))
            ->method('lastInsertId')
            ->willReturnOnConsecutiveCalls('10', '20', '30');

        $databaseMock->expects($this->never())
            ->method('update');

        $databaseMock->expects($this->exactly(4))
            ->method('insert')
            ->willReturnCallback($this->creationInsertCallback($start));

        (new RotationRepository($databaseMock))->create($this->createRotation());
    }

    /**
     * Covers prepending a rotation (priority 0): the repository is expected to query its existing siblings and bump
     * each of their priorities by one before inserting the new rotation.
     *
     * @return void
     */
    public function testCreatePrependsRotationByShiftingExistingSiblings(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        // The siblings to move, returned in descending priority order as the repository requests them
        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([['id' => 100], ['id' => 101]]));

        $shifted = [];
        $databaseMock->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$shifted) {
                $this->assertSame('rotation', $table);
                $this->assertInstanceOf(Expression::class, $data['priority']);
                $this->assertSame('priority + 1', $data['priority']->getStatement());
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                $this->assertArrayHasKey('id = ?', $where);

                $shifted[] = (int) $where['id = ?'];

                return $this->createStub(PDOStatement::class);
            });

        // The new rotation is created with priority 0 afterwards
        $databaseMock->expects($this->exactly(3))
            ->method('lastInsertId')
            ->willReturnOnConsecutiveCalls('10', '20', '30');

        $databaseMock->expects($this->exactly(4))
            ->method('insert')
            ->willReturnCallback($this->creationInsertCallback($start, 0));

        (new RotationRepository($databaseMock))->create($this->createRotation(0));

        $this->assertSame([100, 101], $shifted, 'Both siblings should have been shifted, in the order queried');
    }

    /**
     * Covers updating a rotation. The existing version is expected to be marked as deleted (entry, timeperiod,
     * member and rotation) before the new version is inserted, all within a single set of statements.
     *
     * @return void
     */
    public function testUpdateMarksOldVersionDeletedThenRecreates(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->exactly(3))
            ->method('lastInsertId')
            ->willReturnOnConsecutiveCalls('10', '20', '30');

        // The four soft-deletes that wipe the rotation's current version
        $databaseMock->expects($this->exactly(4))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'timeperiod_entry') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame('n', $where['deleted = ?'], 'Only undeleted entries should be marked deleted');
                    $this->assertInstanceOf(
                        Select::class,
                        $where['timeperiod_id = ?'],
                        'Entries should be scoped to the rotation\'s timeperiod via a sub-select'
                    );
                } elseif ($table === 'timeperiod') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['owned_by_rotation_id = ?' => 42], $where);
                } elseif ($table === 'rotation_member') {
                    $this->assertSame(['deleted' => 'y', 'position' => null], $data);
                    $this->assertSame(['rotation_id = ?' => 42, 'deleted = ?' => 'n'], $where);
                } elseif ($table === 'rotation') {
                    $this->assertSame(['deleted' => 'y', 'priority' => null, 'first_handoff' => null], $data);
                    $this->assertSame(['id = ?' => 42], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        // The new version is created with the same four inserts as a plain creation
        $databaseMock->expects($this->exactly(4))
            ->method('insert')
            ->willReturnCallback($this->creationInsertCallback($start));

        (new RotationRepository($databaseMock))->update($this->createRotation());
    }

    /**
     * Covers deleting a single rotation version: its entries, timeperiod, members and the rotation itself are marked
     * as deleted, and the priorities of all higher-priority siblings (queried via the ORM) are decremented.
     *
     * @return void
     */
    public function testDeleteSoftDeletesVersionAndShiftsSiblings(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $rotation = (new Rotation())->setProperties([
            'id'            => 42,
            'schedule_id'   => 1,
            'priority'      => 3,
            // Providing the timeperiod directly avoids the lookup query the repository would otherwise issue
            'timeperiod'    => (new Timeperiod())->setProperties(['id' => 7])
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        // The single higher-priority sibling whose priority must be decremented
        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([['id' => 200]]));

        $databaseMock->expects($this->never())
            ->method('insert');

        $siblingShifted = false;
        $databaseMock->expects($this->exactly(5))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$siblingShifted) {
                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                $changedAt = $data['changed_at'];
                unset($data['changed_at']);

                if ($table === 'timeperiod_entry') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['timeperiod_id = ?' => 7, 'deleted = ?' => 'n'], $where);
                } elseif ($table === 'timeperiod') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['id = ?' => 7], $where);
                } elseif ($table === 'rotation_member') {
                    $this->assertSame(['deleted' => 'y', 'position' => null], $data);
                    $this->assertSame(['rotation_id = ?' => 42, 'deleted = ?' => 'n'], $where);
                } elseif ($table === 'rotation' && isset($data['deleted'])) {
                    // The rotation version itself
                    $this->assertSame(['deleted' => 'y', 'priority' => null, 'first_handoff' => null], $data);
                    $this->assertSame(['id = ?' => 42], $where);
                } elseif ($table === 'rotation') {
                    // The sibling's priority decrement
                    $siblingShifted = true;
                    $this->assertInstanceOf(Expression::class, $data['priority']);
                    $this->assertSame('priority - 1', $data['priority']->getStatement());
                    $this->assertEquals(['id = ?' => 200], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new RotationRepository($databaseMock))->delete($rotation);

        $this->assertTrue($siblingShifted, 'The higher-priority sibling was not shifted down');
    }
}
