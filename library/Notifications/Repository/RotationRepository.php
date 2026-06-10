<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateInterval;
use DateTime;
use DateTimeZone;
use Generator;
use Icinga\Exception\ConfigurationError;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\Timeperiod;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Util\Json;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use Recurr\Rule;

final class RotationRepository
{
    /**
     * Whether experimental overrides are enabled
     *
     * @var bool
     * @internal Ignore this, seriously!
     */
    public const EXPERIMENTAL_OVERRIDES = false;

    /**
     * Create a `RotationRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the rotation with the given id
     *
     * @param int $id
     *
     * @return ?Rotation
     */
    public function find(int $id): ?Rotation
    {
        /** @var ?Rotation $rotation */
        $rotation  = Rotation::on($this->db)
            ->withColumns('schedule.timezone')
            ->filter(Filter::equal('rotation.id', $id))
            ->first();

        if ($rotation === null) {
            return null;
        }

        if (self::EXPERIMENTAL_OVERRIDES) {
            $getHandoff = function (Rotation $rotation): DateTime {
                $time = match ($rotation->mode) {
                    '24-7'    => $rotation->options['at'],
                    'partial' => $rotation->options['from'],
                    'multi'   => $rotation->options['from_at']
                };

                $handoff = DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $rotation->first_handoff . ' ' . $time,
                    new DateTimeZone($rotation->schedule->timezone)
                );
                if ($handoff === false) {
                    throw new ConfigurationError('Invalid date format');
                }

                return $handoff;
            };

            $rotation->previousHandoff = $getHandoff($rotation);

            /** @var ?TimeperiodEntry $previousShift */
            $previousShift = TimeperiodEntry::on($this->db)
                ->columns('until_time')
                ->filter(Filter::all(
                    Filter::equal('timeperiod.rotation.schedule_id', $rotation->schedule_id),
                    Filter::equal('timeperiod.rotation.priority', $rotation->priority),
                    Filter::unequal('timeperiod.owned_by_rotation_id', $rotation->id),
                    Filter::lessThanOrEqual('until_time', $rotation->previousHandoff),
                    Filter::like('until_time', '*')
                ))
                ->orderBy('until_time', SORT_DESC)
                ->first();
            if ($previousShift !== null) {
                $rotation->previousShift = $previousShift->until_time->setTimezone(
                    new DateTimeZone($rotation->schedule->timezone)
                );
            }

            /** @var ?Rotation $newerRotation */
            $newerRotation = Rotation::on($this->db)
                ->columns(['first_handoff', 'options', 'mode', 'schedule.timezone'])
                ->filter(Filter::all(
                    Filter::equal('schedule_id', $rotation->schedule_id),
                    Filter::equal('priority', $rotation->priority),
                    Filter::greaterThan('first_handoff', $rotation->first_handoff)
                ))
                ->orderBy('first_handoff', SORT_ASC)
                ->first();
            if ($newerRotation !== null) {
                $rotation->nextHandoff = $getHandoff($newerRotation);
            }
        }

        return $rotation;
    }

    /**
     * Insert a new rotation in the database
     *
     * @param Rotation $rotation
     *
     * @return Generator<int, DateTime> The first handoff of the rotation, as value
     */
    private function prepareCreation(Rotation $rotation): Generator
    {
        $members = iterator_to_array($rotation->member);

        $rules = $rotation->yieldRecurrenceRules(count($members));
        $firstHandoff = $rules->current()[0]->getStartDate();

        // Only continue, once the caller is ready
        if (! yield $firstHandoff) {
            return;
        }

        $now = new DateTime();
        if ($firstHandoff < $now) {
            $actual_handoff = (int) $now->format('U.u') * 1000.0;
        } else {
            $actual_handoff = $firstHandoff->format('U.u') * 1000.0;
        }

        $changedAt = (int) (new DateTime())->format("Uv");

        $this->db->insert('rotation', [
            'schedule_id' => $rotation->schedule_id,
            'priority' => $rotation->priority,
            'name' => $rotation->name,
            'mode' => $rotation->mode,
            'options' => is_string($rotation->options) ? $rotation->options : Json::encode($rotation->options),
            'first_handoff' => $rotation->first_handoff,
            'actual_handoff' => $actual_handoff,
            'changed_at' => $changedAt,
            'deleted' => 'n'
        ]);

        $rotation->id = $this->db->lastInsertId();

        $this->db->insert('timeperiod', ['owned_by_rotation_id' => $rotation->id, 'changed_at' => $changedAt]);
        $timeperiodId = $this->db->lastInsertId();

        $knownMembers = [];
        foreach ($rules as $position => [$rrule, $shiftDuration]) {
            /** @var Rule $rrule */
            /** @var DateInterval $shiftDuration */

            if (isset($knownMembers[$position])) {
                $memberId = $knownMembers[$position];
            } else {
                $member = $members[$position];
                $member->rotation_id = $rotation->id;

                $this->db->insert('rotation_member', [
                    'rotation_id' => $member->rotation_id,
                    'contact_id' => $member->contact_id,
                    'contactgroup_id' => $member->contactgroup_id,
                    'position' => $member->position,
                    'changed_at' => $changedAt,
                    'deleted' => 'n'
                ]);

                $memberId = $this->db->lastInsertId();
                $knownMembers[$position] = $memberId;
            }

            $endTime = (clone $rrule->getStartDate())->add($shiftDuration)->format('U.u') * 1000.0;

            $untilTime = null;
            if (! $rrule->repeatsIndefinitely()) {
                // Our recurrence rules only repeat definitely due to a set until time
                $untilTime = (clone $rrule->getUntil())->add($shiftDuration)->format('U.u') * 1000.0;
            }

            $this->db->insert('timeperiod_entry', [
                'timeperiod_id' => $timeperiodId,
                'rotation_member_id' => $memberId,
                'start_time' => $rrule->getStartDate()->format('U.u') * 1000.0,
                'end_time' => $endTime,
                'until_time' => $untilTime,
                'timezone' => $rrule->getStartDate()->getTimezone()->getName(),
                'rrule' => $rrule->getString(Rule::TZ_FIXED),
                'changed_at' => $changedAt
            ]);
        }
    }

    /**
     * Store a new rotation
     *
     * The rotation's id will be set after successful creation
     *
     * @param Rotation $rotation
     *
     * @return void
     */
    public function create(Rotation $rotation): void
    {
        if ($rotation->priority === 0) {
            // Only the configuration UI allows to prepend a rotation so
            // there's no need to expect a collision on other priorities
            $rotationsToMove = Rotation::on($this->db)
                ->columns('id')
                ->filter(Filter::equal('schedule_id', $rotation->schedule_id))
                ->orderBy('priority', SORT_DESC);

            foreach ($rotationsToMove as $sibling) {
                $this->db->update(
                    'rotation',
                    [
                        'priority'      => new Expression('priority + 1'),
                        'changed_at'    => (int) (new DateTime())->format("Uv")
                    ],
                    ['id = ?' => $sibling->id]
                );
            }
        }

        $this->prepareCreation($rotation)->send(true);
    }

    /**
     * Update a rotation
     *
     * @param Rotation $rotation
     *
     * @return void
     */
    public function update(Rotation $rotation): void
    {
        // Delay the creation, avoids intermediate constraint failures
        $createStmt = $this->prepareCreation($rotation);

        $allEntriesRemoved = true;
        $changedAt = (int) (new DateTime())->format("Uv");
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];
        if (self::EXPERIMENTAL_OVERRIDES) {
            // We only show a single name, even in case of multiple versions of a rotation.
            // To avoid confusion, we update all versions upon change of the name
            $this->db->update(
                'rotation',
                ['name' => $rotation->name, 'changed_at' => $changedAt],
                ['schedule_id = ?' => $rotation->schedule_id, 'priority = ?' => $rotation->priority]
            );

            $firstHandoff = $createStmt->current();
            $timeperiodEntries = TimeperiodEntry::on($this->db)
                ->filter(Filter::equal('timeperiod.owned_by_rotation_id', $rotation->id));

            foreach ($timeperiodEntries as $timeperiodEntry) {
                $timeperiodEntry->start_time->setTimezone(new DateTimeZone($rotation->schedule->timezone));
                $timeperiodEntry->end_time->setTimezone(new DateTimeZone($rotation->schedule->timezone));

                /** @var TimeperiodEntry $timeperiodEntry */
                $rrule = $timeperiodEntry->toRecurrenceRule();
                $shiftDuration = $timeperiodEntry->start_time->diff($timeperiodEntry->end_time);
                $remainingHandoffs = Rotation::calculateRemainingHandoffs($rrule, $shiftDuration, $firstHandoff);
                $lastHandoff = array_shift($remainingHandoffs);

                // If there is a gap between the last handoff and the next one, insert a single occurrence to fill it
                if (! empty($remainingHandoffs)) {
                    [$gapStart, $gapEnd] = $remainingHandoffs[0];

                    $allEntriesRemoved = false;
                    $this->db->insert('timeperiod_entry', [
                        'timeperiod_id' => $timeperiodEntry->timeperiod_id,
                        'rotation_member_id' => $timeperiodEntry->rotation_member_id,
                        'start_time' => $gapStart->format('U.u') * 1000.0,
                        'end_time' => $gapEnd->format('U.u') * 1000.0,
                        'until_time' => $gapEnd->format('U.u') * 1000.0,
                        'timezone' => $gapStart->getTimezone()->getName(),
                        'changed_at' => $changedAt
                    ]);
                }

                $lastShiftEnd = null;
                if ($lastHandoff !== null) {
                    $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
                }

                if ($lastHandoff === null) {
                    // If the handoff didn't happen at all, the entry can safely be removed
                    $this->db->update('timeperiod_entry', $markAsDeleted, ['id = ?' => $timeperiodEntry->id]);
                } else {
                    $allEntriesRemoved = false;
                    $this->db->update('timeperiod_entry', [
                        'until_time'    => $lastShiftEnd->format('U.u') * 1000.0,
                        'rrule'         => $rrule->setUntil($lastHandoff)->getString(Rule::TZ_FIXED),
                        'changed_at'    => $changedAt
                    ], ['id = ?' => $timeperiodEntry->id]);
                }
            }
        } else {
            $this->db->update(
                'timeperiod_entry',
                $markAsDeleted,
                [
                    'deleted = ?'       => 'n',
                    'timeperiod_id = ?' => (new Select())
                        ->from('timeperiod')
                        ->columns('id')
                        ->where(['owned_by_rotation_id = ?' => $rotation->id])
                ]
            );
        }

        if ($allEntriesRemoved) {
            $this->db->update('timeperiod', $markAsDeleted, ['owned_by_rotation_id = ?' => $rotation->id]);

            $this->db->update(
                'rotation_member',
                $markAsDeleted + ['position' => null],
                ['rotation_id = ?' => $rotation->id, 'deleted = ?' => 'n']
            );

            $this->db->update(
                'rotation',
                $markAsDeleted + ['priority' => null, 'first_handoff' => null],
                ['id = ?' => $rotation->id]
            );
        }

        // Once constraint failures are impossible, create the new version
        $createStmt->send(true);
    }

    /**
     * Delete the rotation's version from the database
     *
     * @param Rotation $rotation
     *
     * @return void
     */
    public function delete(Rotation $rotation): void
    {
        if ($rotation->timeperiod instanceof Timeperiod) {
            $timeperiodId = $rotation->timeperiod->id;
        } else {
            $timeperiodId = $rotation->timeperiod->columns('id')->first()->id;
        }

        $changedAt = (int) (new DateTime())->format("Uv");
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];

        $this->db->update('timeperiod_entry', $markAsDeleted, [
            'timeperiod_id = ?' => $timeperiodId,
            'deleted = ?' => 'n'
        ]);
        $this->db->update('timeperiod', $markAsDeleted, ['id = ?' => $timeperiodId]);

        $this->db->update(
            'rotation_member',
            $markAsDeleted + ['position' => null],
            ['rotation_id = ?' => $rotation->id, 'deleted = ?' => 'n']
        );

        $this->db->update(
            'rotation',
            $markAsDeleted + ['priority' => null, 'first_handoff' => null],
            ['id = ?' => $rotation->id]
        );

        $requirePriorityUpdate = true;
        if (self::EXPERIMENTAL_OVERRIDES) {
            $rotations = Rotation::on($this->db)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('schedule_id', $rotation->schedule_id))
                ->filter(Filter::equal('priority', $rotation->priority))
                ->first();

            $requirePriorityUpdate = $rotations === null;
        }

        if ($requirePriorityUpdate) {
            $siblings = Rotation::on($this->db)
                ->columns('id')
                ->filter(Filter::equal('schedule_id', $rotation->schedule_id))
                ->filter(Filter::greaterThan('priority', $rotation->priority))
                ->orderBy('priority', SORT_ASC);

            foreach ($siblings as $sibling) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $sibling->id]
                );
            }
        }
    }

    /**
     * Remove all versions of the rotation from the database
     *
     * @param Rotation $rotation
     *
     * @return void
     */
    public function wipe(Rotation $rotation): void
    {
        $siblings = Rotation::on($this->db)
            ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
            ->filter(Filter::equal('schedule_id', $rotation->schedule_id))
            ->filter(Filter::equal('priority', $rotation->priority));

        /** @var Rotation $sibling */
        foreach ($siblings as $sibling) {
            $this->delete($sibling);
        }
    }

    /**
     * Duplicate a rotation
     *
     * @param Rotation $original
     * @param ?int $scheduleId The schedule the new rotation should belong to
     *
     * @return void
     */
    public function duplicate(Rotation $original, ?int $scheduleId = null): void
    {
        if ($scheduleId !== null) {
            $original->schedule_id = $scheduleId;
        } else {
            // If the schedule remains the same, the priority is already occupied
            $original->priority = 0;
        }

        $this->create($original);
    }

    /**
     * Move a rotation
     *
     * @param Rotation $rotation
     * @param int $newPriority
     *
     * @return void
     */
    public function move(Rotation $rotation, int $newPriority): void
    {
        $changedAt = (int) (new DateTime())->format("Uv");
        // Free up the current priority used by the rotation in question
        $this->db->update('rotation', ['priority' => null, 'deleted' => 'y'], ['id = ?' => $rotation->id]);

        // Update the priorities of the rotations that are affected by the move
        if ($newPriority < $rotation->priority) {
            $siblings = $this->db->select(
                (new Select())
                    ->columns('id')
                    ->from('rotation')
                    ->where([
                        'schedule_id = ?' => $rotation->schedule_id,
                        'priority >= ?' => $newPriority,
                        'priority < ?' => $rotation->priority
                    ])
                    ->orderBy('priority DESC')
            );
            foreach ($siblings as $sibling) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority + 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $sibling->id]
                );
            }
        } elseif ($newPriority > $rotation->priority) {
            $siblings = $this->db->select(
                (new Select())
                    ->columns('id')
                    ->from('rotation')
                    ->where([
                        'schedule_id = ?' => $rotation->schedule_id,
                        'priority > ?' => $rotation->priority,
                        'priority <= ?' => $newPriority
                    ])
                    ->orderBy('priority ASC')
            );
            foreach ($siblings as $sibling) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $sibling->id]
                );
            }
        }

        // Now insert the rotation at the new priority
        $this->db->update(
            'rotation',
            ['priority' => $newPriority, 'changed_at' => $changedAt, 'deleted' => 'n'],
            ['id = ?' => $rotation->id]
        );
    }
}
