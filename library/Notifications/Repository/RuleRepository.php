<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\RuleEntry;
use Icinga\Module\Notifications\Form\Data\RuleEntryRecipient;
use Icinga\Module\Notifications\Form\Data\Rule as RuleData;
use Icinga\Module\Notifications\Model\Rule;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

final class RuleRepository
{
    /**
     * Create a `RuleRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the event rule with the given ID
     *
     * @param int $id
     *
     * @return ?Rule
     */
    public function find(int $id): ?Rule
    {
        return Rule::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();
    }

    /**
     * Store a new event rule
     *
     * @param RuleData $rule
     *
     * @return int The rule's ID
     */
    public function create(RuleData $rule): int
    {
        $model = (new Rule())->setNew();

        $model->name = $rule->name;
        $model->type = 'escalation';
        $model->source_type = $rule->sourceType;
        $model->object_filter = $rule->objectFilter;

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given event rule
     *
     * @param RuleData $rule
     *
     * @return void
     *
     * @throws InvalidArgumentException if the rule does not exist
     */
    public function update(RuleData $rule): void
    {
        $model = $this->find($rule->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update an event rule that does not exist');
        }

        $model->name = $rule->name;
        if ($rule->sourceType !== $model->source_type) {
            $model->source_type = $rule->sourceType;
            $model->object_filter = null;
        } elseif ($rule->objectFilter !== null) {
            $model->object_filter = $rule->objectFilter ?: null;
        }

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Delete the event rule with the given ID
     *
     * @param int $id
     *
     * @return Rule The deleted rule
     *
     * @throws InvalidArgumentException if the rule does not exist
     */
    public function delete(int $id): Rule
    {
        $rule = $this->find($id)?->setNew(false);
        if ($rule === null) {
            throw new InvalidArgumentException('Cannot delete an event rule that does not exist');
        }

        $entryRepository = new RuleEntryRepository($this->db);

        $entries = $rule->rule_entry->query()->columns('id');
        foreach ($entries as $entry) {
            $entryRepository->delete($entry->id);
        }

        (new EntityManager($this->db))->save($rule->delete());

        return $rule;
    }

    /**
     * Duplicate an event rule
     *
     * @param RuleData $rule
     *
     * @return int
     */
    public function duplicate(RuleData $rule): int
    {
        $original = $this->find($rule->id);
        if ($original === null) {
            throw new InvalidArgumentException(
                'Cannot duplicate an event rule that does not exist in the database'
            );
        } elseif (isset($original->timeperiod_id)) {
            throw new NotImplementedError(
                'Duplicating event rules with time periods is not yet supported'
            );
        }

        $ruleId = $this->create(new RuleData(
            null,
            $rule->name,
            $rule->sourceType,
            $rule->objectFilter ?? $original->object_filter
        ));

        $entryRepository = new RuleEntryRepository($this->db);

        foreach ($original->rule_entry as $entry) {
            $recipients = [];
            foreach ($entry->rule_entry_recipient as $recipient) {
                [$recipientType, $recipientId] = match (true) {
                    isset($recipient->contactgroup_id) => ['contact_group', $recipient->contactgroup_id],
                    isset($recipient->schedule_id) => ['schedule', $recipient->schedule_id],
                    default => ['contact', $recipient->contact_id]
                };

                $recipients[] = new RuleEntryRecipient(
                    null,
                    $recipientType,
                    $recipientId,
                    $recipient->channel_id
                );
            }

            $entryRepository->create(new RuleEntry(
                null,
                $entry->position,
                $entry->condition,
                $recipients,
                $ruleId
            ));
        }

        return $ruleId;
    }
}
