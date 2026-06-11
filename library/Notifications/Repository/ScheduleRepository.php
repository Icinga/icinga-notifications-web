<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Rotation as RotationData;
use Icinga\Module\Notifications\Form\Data\Schedule as ScheduleData;
use Icinga\Module\Notifications\Model\Schedule;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;

final class ScheduleRepository
{
    /**
     * Create a `ScheduleRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the schedule with the given id
     *
     * @param int $id
     *
     * @return ?Schedule
     */
    public function find(int $id): ?Schedule
    {
        return Schedule::on($this->db)
            ->filter(Filter::equal('schedule.id', $id))
            ->filter(Filter::equal('schedule.deleted', false))
            ->first();
    }

    /**
     * Store a new schedule
     *
     * @param ScheduleData $schedule
     *
     * @return int The schedule's ID
     */
    public function create(ScheduleData $schedule): int
    {
        $model = (new Schedule())->setNew();
        $model->name = $schedule->name;
        $model->timezone = $schedule->timezone;

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update a schedule
     *
     * @param ScheduleData $schedule
     *
     * @return void
     */
    public function update(ScheduleData $schedule): void
    {
        if (! isset($schedule->id)) {
            throw new InvalidArgumentException('Cannot update a schedule that does not have an ID');
        }

        $model = $this->find($schedule->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update a schedule that does not exist in the database');
        }

        $model->name = $schedule->name;
        if (isset($schedule->timezone)) {
            $model->timezone = $schedule->timezone;

            $rotationRepository = new RotationRepository($this->db);

            $rotations = $model->rotation
                ->query()
                ->filter(Filter::equal('deleted', false))
                ->orderBy('priority', SORT_ASC);
            foreach ($rotations as $rotation) {
                $currentMembers = $rotation->member
                    ->filter(Filter::equal('deleted', false))
                    ->orderBy('position', SORT_ASC);

                $members = [];
                foreach ($currentMembers as $member) {
                    if ($member->contact_id !== null) {
                        $members[] = ['contact', $member->contact_id];
                    } else {
                        $members[] = ['contact_group', $member->contactgroup_id];
                    }
                }

                $rotationRepository->update(new RotationData(
                    $rotation->id,
                    $schedule->id,
                    $rotation->priority,
                    $rotation->name,
                    $rotation->mode,
                    $rotation->options,
                    $members,
                    DateTimeImmutable::createFromFormat(
                        'Y-m-d',
                        $rotation->first_handoff,
                        new DateTimeZone($schedule->timezone)
                    )
                ));
            }
        }

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Delete a schedule and de-reference it from any escalation rules
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $schedule = $this->find($id)?->setNew(false);
        if ($schedule === null) {
            throw new InvalidArgumentException('Cannot delete a schedule that does not exist in the database');
        }

        $rotations = $schedule->rotation->query()
            ->columns('id')
            ->filter(Filter::equal('deleted', false))
            ->orderBy('priority', SORT_DESC);
        foreach ($rotations as $rotation) {
            (new RotationRepository($this->db))->delete($rotation->id);
        }

        $schedule->rule_escalation
            ->query()
            ->columns('id')
            ->filter(Filter::equal('deleted', false));
        foreach ($schedule->rule_escalation as $escalation) {
            $schedule->rule_escalation->detach($escalation);

            $otherRecipients = $escalation->rule_escalation_recipient
                ->query()
                ->columns([new Expression('1')])
                ->filter(Filter::all(
                    Filter::unequal('schedule_id', $schedule->id),
                    Filter::equal('deleted', 'n')
                ))
                ->first();
            if ($otherRecipients === null) {
                $escalation->position = null;
                $escalation->delete(); // TODO: EscalationRepository::delete(…)
            }
        }

        $schedule->delete();

        (new EntityManager($this->db))->save($schedule);
    }

    /**
     * Duplicate a schedule
     *
     * @param ScheduleData $schedule
     *
     * @return int The ID of the new schedule
     */
    public function duplicate(ScheduleData $schedule): int
    {
        $original = $this->find($schedule->id);
        if ($original === null) {
            throw new InvalidArgumentException('Cannot duplicate a schedule that does not exist in the database');
        }

        $scheduleId = $this->create($schedule);

        $rotationRepository = new RotationRepository($this->db);

        $rotations = $original->rotation
            ->filter(Filter::equal('deleted', false))
            ->orderBy('priority', SORT_ASC);
        foreach ($rotations as $rotation) {
            $currentMembers = $rotation->member
                ->filter(Filter::equal('deleted', false))
                ->orderBy('position', SORT_ASC);

            $members = [];
            foreach ($currentMembers as $member) {
                if ($member->contact_id !== null) {
                    $members[] = ['contact', $member->contact_id];
                } else {
                    $members[] = ['contact_group', $member->contactgroup_id];
                }
            }

            $rotationRepository->create(new RotationData(
                null,
                $scheduleId,
                $rotation->priority,
                $rotation->name,
                $rotation->mode,
                $rotation->options,
                $members,
                DateTimeImmutable::createFromFormat(
                    'Y-m-d',
                    $rotation->first_handoff,
                    new DateTimeZone($schedule->timezone)
                )
            ));
        }

        return $scheduleId;
    }
}
