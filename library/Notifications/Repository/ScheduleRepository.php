<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Sql\Connection;
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
        /** @var ?Schedule $schedule */
        $schedule = Schedule::on($this->db)
            ->filter(Filter::equal('schedule.id', $id))
            ->first();

        return $schedule;
    }

    /**
     * Store a new schedule
     *
     * The schedule's id will be set after successful creation
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    public function create(Schedule $schedule): void
    {
        $this->db->insert('schedule', [
            'name' => $schedule->name,
            'changed_at' => (int) (new DateTime())->format("Uv"),
            'timezone' => $schedule->timezone
        ]);

        $schedule->id = $this->db->lastInsertId();
    }

    /**
     * Update a schedule
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    public function update(Schedule $schedule): void
    {
        $this->db->update('schedule', [
            'name' => $schedule->name,
            'changed_at' => (int) (new DateTime())->format("Uv"),
            'timezone' => $schedule->timezone
        ], ['id = ?' => $schedule->id]);
    }

    /**
     * Delete a schedule and de-reference it from any escalation rules
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    public function delete(Schedule $schedule): void
    {
        $rotations = Rotation::on($this->db)
            ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
            ->filter(Filter::equal('schedule_id', $schedule->id))
            ->orderBy('priority', SORT_DESC);

        foreach ($rotations as $rotation) {
            /** @var Rotation $rotation */
            (new RotationRepository($this->db))->delete($rotation);
        }

        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];

        $escalationIds = $this->db->fetchCol(
            RuleEscalationRecipient::on($this->db)
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('schedule_id', $schedule->id))
                ->assembleSelect()
        );

        $this->db->update('rule_escalation_recipient', $markAsDeleted, ['schedule_id = ?' => $schedule->id]);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->db->fetchCol(
                RuleEscalationRecipient::on($this->db)
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('schedule_id', $schedule->id)
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $this->db->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $this->db->update('schedule', $markAsDeleted, ['id = ?' => $schedule->id]);
    }

    /**
     * Duplicate a schedule
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    public function duplicate(Schedule $schedule): void
    {
        $this->db->insert('schedule', [
            'name' => $schedule->name,
            'changed_at' => (int) (new DateTime())->format("Uv"),
            'timezone' => $schedule->timezone
        ]);

        $scheduleId = (int) $this->db->lastInsertId();

        $rotationRepository = new RotationRepository($this->db);
        foreach ($schedule->rotation as $rotation) {
            $rotationRepository->duplicate($rotation, $scheduleId);
        }

        $schedule->id = $scheduleId;
    }
}
