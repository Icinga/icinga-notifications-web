<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateInterval;
use DateTime;
use DateTimeZone;
use Generator;
use Icinga\Exception\ConfigurationError;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\Timeperiod;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Util\Json;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use Recurr\Rule;
use RuntimeException;

final class RotationRepository
{
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

        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
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
     * @param RotationData $rotation
     *
     * @return Generator<bool, int, DateTime, void> The first handoff of the rotation, as value
     */
    private function prepareCreation(RotationData $rotation): Generator
    {
        $model = (new Rotation())->setNew();

        $rules = $rotation->yieldRecurrenceRules();
        $firstHandoff = $rules->current()[0]->getStartDate();

        // Only continue, once the caller is ready
        if (! (yield $firstHandoff)) {
            return null;
        }

        $model->schedule_id = $rotation->scheduleId;
        $model->priority = $rotation->priority;
        $model->name = $rotation->name;
        $model->mode = $rotation->mode;
        $model->options = Json::encode($rotation->options);
        $model->first_handoff = $rotation->firstHandoff->format('Y-m-d');
        $model->actual_handoff = max($firstHandoff, new DateTime());
        $model->timeperiod = (new Timeperiod())->setNew();
        $model->timeperiod->timeperiod_entry = Collection::create(TimeperiodEntry::class, []);

        $knownMembers = [];
        foreach ($rules as $position => [$rrule, $shiftDuration]) {
            /** @var Rule $rrule */
            /** @var DateInterval $shiftDuration */

            if (! isset($knownMembers[$position])) {
                [$type, $id] = $rotation->members[$position];

                $member = (new RotationMember())->setNew();
                $member->position = $position;
                match ($type) {
                    'contact' => $member->contact_id = $id,
                    'contact_group' => $member->contactgroup_id = $id
                };

                $knownMembers[$position] = $member;
            }

            $endTime = (clone $rrule->getStartDate())->add($shiftDuration);

            $untilTime = null;
            if (! $rrule->repeatsIndefinitely()) {
                // Our recurrence rules only repeat definitely due to a set until time
                $untilTime = (clone $rrule->getUntil())->add($shiftDuration);
            }

            $entry = new TimeperiodEntry();
            $entry->start_time = $rrule->getStartDate();
            $entry->end_time = $endTime;
            $entry->until_time = $untilTime;
            $entry->timezone = $rrule->getStartDate()->getTimezone()->getName();
            $entry->rrule = $rrule->getString(Rule::TZ_FIXED);
            $entry->member = $knownMembers[$position];

            $model->timeperiod->timeperiod_entry->attach($entry->setNew());
        }

        $model->member = Collection::create(RotationMember::class, $knownMembers);

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Store a new rotation
     *
     * @param RotationData $rotation
     *
     * @return void
     */
    public function create(RotationData $rotation): void
    {
        if ($rotation->priority === 0) {
            // Only the configuration UI allows to prepend a rotation so
            // there's no need to expect a collision on other priorities
            $rotationsToMove = Rotation::on($this->db)
                ->columns(['id', 'priority'])
                ->filter(Filter::equal('schedule_id', $rotation->scheduleId))
                ->orderBy('priority', SORT_DESC);

            $entityManager = new EntityManager($this->db);
            foreach ($rotationsToMove as $sibling) {
                $sibling->setNew(false);
                $sibling->priority += 1;
                $entityManager->save($sibling);
            }
        }

        $this->prepareCreation($rotation)->send(true);
    }

    /**
     * Update a rotation
     *
     * @param RotationData $rotation
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given rotation does not have an ID
     */
    public function update(RotationData $rotation): void
    {
        if (! isset($rotation->id)) {
            throw new InvalidArgumentException('Cannot update a rotation that does not have an ID');
        }

        // Delay the creation, avoids intermediate constraint failures
        $createStmt = $this->prepareCreation($rotation);
        $entityManager = new EntityManager($this->db);

        $allEntriesRemoved = true;
        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
            // We only show a single name, even in case of multiple versions of a rotation.
            // To avoid confusion, we update all versions upon change of the name
            $versions = Rotation::on($this->db)
                ->columns(['id', 'name'])
                ->filter(Filter::equal('schedule_id', $rotation->scheduleId))
                ->filter(Filter::equal('priority', $rotation->priority));
            foreach ($versions as $version) {
                $version->setNew(false);
                $version->name = $rotation->name;
                $entityManager->save($version);
            }

            $firstHandoff = $createStmt->current();
            $timeperiodEntries = TimeperiodEntry::on($this->db)
                ->filter(Filter::equal('timeperiod.owned_by_rotation_id', $rotation->id));

            foreach ($timeperiodEntries as $timeperiodEntry) {
                $timeperiodEntry->start_time->setTimezone($rotation->firstHandoff->getTimezone());
                $timeperiodEntry->end_time->setTimezone($rotation->firstHandoff->getTimezone());

                /** @var TimeperiodEntry $timeperiodEntry */
                $rrule = $timeperiodEntry->toRecurrenceRule();
                $shiftDuration = $timeperiodEntry->start_time->diff($timeperiodEntry->end_time);
                $remainingHandoffs = RotationData::calculateRemainingHandoffs($rrule, $shiftDuration, $firstHandoff);
                $lastHandoff = array_shift($remainingHandoffs);

                // If there is a gap between the last handoff and the next one, insert a single occurrence to fill it
                if (! empty($remainingHandoffs)) {
                    [$gapStart, $gapEnd] = $remainingHandoffs[0];

                    $filler = (new TimeperiodEntry())->setNew();
                    $filler->timeperiod_id = $timeperiodEntry->timeperiod_id;
                    $filler->rotation_member_id = $timeperiodEntry->rotation_member_id;
                    $filler->start_time = $gapStart;
                    $filler->end_time = $gapEnd;
                    $filler->until_time = $gapEnd;
                    $filler->timezone = $gapStart->getTimezone()->getName();

                    $entityManager->save($filler);
                    $allEntriesRemoved = false;
                }

                $lastShiftEnd = null;
                if ($lastHandoff !== null) {
                    $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
                }

                $timeperiodEntry->setNew(false);
                if ($lastHandoff === null) {
                    // If the handoff didn't happen at all, the entry can safely be removed
                    $timeperiodEntry->delete();
                } else {
                    $allEntriesRemoved = false;
                    $timeperiodEntry->until_time = $lastShiftEnd;
                    $timeperiodEntry->rrule = $rrule->setUntil($lastHandoff)->getString(Rule::TZ_FIXED);
                }

                $entityManager->save($timeperiodEntry);
            }
        } else {
            $entries = TimeperiodEntry::on($this->db)
                ->columns('id')
                ->filter(Filter::equal('timeperiod.owned_by_rotation_id', $rotation->id));
            foreach ($entries as $entry) {
                $entityManager->save($entry->setNew(false)->delete());
            }
        }

        if ($allEntriesRemoved) {
            $this->performDeletion($rotation->id, false);
        }

        // Once constraint failures are impossible, create the new version
        $createStmt->send(true);
    }

    /**
     * Delete the rotation's version from the database
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $this->performDeletion($id);
    }

    /**
     * Actually perform the deletion
     *
     * This is an internal alias to not make the second argument public.
     *
     * @param int $id
     * @param bool $requirePriorityUpdate
     *
     * @return void
     */
    private function performDeletion(int $id, bool $requirePriorityUpdate = true): void
    {
        $model = Rotation::on($this->db)
            ->withColumns(['timeperiod.id'])
            ->filter(Filter::equal('id', $id))
            ->first()?->setNew(false);
        if ($model === null) {
            throw new RuntimeException('Cannot delete a rotation that does not exist in the database');
        }

        $entityManager = new EntityManager($this->db);
        $freedPriority = $model->priority;

        $model->timeperiod->setNew(false);
        $model->timeperiod->timeperiod_entry = [];
        $model->timeperiod->delete();

        $model->member->query()->columns(['id', 'position']);
        foreach ($model->member as $member) {
            $member->position = null;
            $member->delete();
        }

        $model->priority = null;
        $model->first_handoff = null;
        $model->delete();

        $entityManager->save($model);

        if (! $requirePriorityUpdate) {
            return;
        }

        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
            $versions = Rotation::on($this->db)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('schedule_id', $model->schedule_id))
                ->filter(Filter::equal('priority', $freedPriority))
                ->first();

            $requirePriorityUpdate = $versions === null;
        }

        if ($requirePriorityUpdate) {
            $siblings = Rotation::on($this->db)
                ->columns(['id', 'priority'])
                ->filter(Filter::equal('schedule_id', $model->schedule_id))
                ->filter(Filter::greaterThan('priority', $freedPriority))
                ->orderBy('priority', SORT_ASC);

            foreach ($siblings as $sibling) {
                $sibling->setNew(false);
                $sibling->priority -= 1;
                $entityManager->save($sibling);
            }
        }
    }

    /**
     * Remove all versions of the rotation from the database
     *
     * @param RotationData $rotation
     *
     * @return void
     */
    public function wipe(RotationData $rotation): void
    {
        $siblings = Rotation::on($this->db)
            ->columns('id')
            ->filter(Filter::equal('schedule_id', $rotation->scheduleId))
            ->filter(Filter::equal('priority', $rotation->priority));
        foreach ($siblings as $sibling) {
            $this->delete($sibling->id);
        }
    }

    /**
     * Move a rotation
     *
     * @param int $id
     * @param int $newPriority
     *
     * @return void
     */
    public function move(int $id, int $newPriority): void
    {
        $model = Rotation::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first()?->setNew(false);
        if ($model === null) {
            throw new RuntimeException('Cannot move a rotation that does not exist in the database');
        }

        $entityManager = new EntityManager($this->db);

        // Free up the current priority used by the rotation in question
        $freedPriority = $model->priority;
        $model->priority = null;
        $entityManager->save($model->delete());

        // Update the priorities of the rotations that are affected by the move
        if ($newPriority < $freedPriority) {
            $siblings = Rotation::on($this->db)
                ->columns(['id', 'priority'])
                ->filter(Filter::equal('schedule_id', $model->schedule_id))
                ->filter(Filter::greaterThanOrEqual('priority', $newPriority))
                ->filter(Filter::lessThan('priority', $freedPriority))
                ->orderBy('priority', SORT_DESC);
            foreach ($siblings as $sibling) {
                $sibling->setNew(false);
                $sibling->priority += 1;
                $entityManager->save($sibling);
            }
        } elseif ($newPriority > $freedPriority) {
            $siblings = Rotation::on($this->db)
                ->columns(['id', 'priority'])
                ->filter(Filter::equal('schedule_id', $model->schedule_id))
                ->filter(Filter::lessThanOrEqual('priority', $newPriority))
                ->filter(Filter::greaterThan('priority', $freedPriority))
                ->orderBy('priority', SORT_ASC);
            foreach ($siblings as $sibling) {
                $sibling->setNew(false);
                $sibling->priority -= 1;
                $entityManager->save($sibling);
            }
        }

        // Now insert the rotation at the new priority
        $model->priority = $newPriority;
        $entityManager->save($model->restore());
    }
}
