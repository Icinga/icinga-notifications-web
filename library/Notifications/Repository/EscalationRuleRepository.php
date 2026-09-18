<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Form\Data\EscalationRecipient;
use Icinga\Module\Notifications\Form\Data\EscalationRule;
use Icinga\Module\Notifications\Model\Rule;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

final class EscalationRuleRepository
{
    /**
     * Create a `EscalationRuleRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the escalation rule with the given ID
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
     * Store a new escalation rule
     *
     * @param EscalationRule $rule
     *
     * @return int The rule's ID
     */
    public function create(EscalationRule $rule): int
    {
        $model = (new Rule())->setNew();

        $model->name = $rule->name;
        $model->source_type = $rule->sourceType;
        $model->object_filter = $rule->objectFilter;

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given escalation rule
     *
     * @param EscalationRule $rule
     *
     * @return void
     *
     * @throws InvalidArgumentException if the rule does not exist
     */
    public function update(EscalationRule $rule): void
    {
        $model = $this->find($rule->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update an escalation rule that does not exist');
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
     * Delete the escalation rule with the given ID
     *
     * @param int $id
     *
     * @return void
     *
     * @throws InvalidArgumentException if the rule does not exist
     */
    public function delete(int $id): void
    {
        $rule = $this->find($id)?->setNew(false);
        if ($rule === null) {
            throw new InvalidArgumentException('Cannot delete an escalation rule that does not exist');
        }

        $escalationRepository = new EscalationRepository($this->db);

        $escalations = $rule->rule_escalation->query()->columns('id');
        foreach ($escalations as $escalation) {
            $escalationRepository->delete($escalation->id);
        }

        (new EntityManager($this->db))->save($rule->delete());
    }

    /**
     * Duplicate an escalation rule
     *
     * @param EscalationRule $rule
     *
     * @return int
     */
    public function duplicate(EscalationRule $rule): int
    {
        $original = $this->find($rule->id);
        if ($original === null) {
            throw new InvalidArgumentException(
                'Cannot duplicate an escalation rule that does not exist in the database'
            );
        } elseif (isset($original->timeperiod_id)) {
            throw new NotImplementedError(
                'Duplicating escalation rules with time periods is not yet supported'
            );
        }

        $ruleId = $this->create(new EscalationRule(
            null,
            $rule->name,
            $rule->sourceType,
            $rule->objectFilter ?? $original->object_filter
        ));

        $escalationRepository = new EscalationRepository($this->db);

        foreach ($original->rule_escalation as $escalation) {
            $recipients = [];
            foreach ($escalation->rule_escalation_recipient as $recipient) {
                [$recipientType, $recipientId] = match (true) {
                    isset($recipient->contactgroup_id) => ['contact_group', $recipient->contactgroup_id],
                    isset($recipient->schedule_id) => ['schedule', $recipient->schedule_id],
                    default => ['contact', $recipient->contact_id]
                };

                $recipients[] = new EscalationRecipient(
                    null,
                    $recipientType,
                    $recipientId,
                    $recipient->channel_id
                );
            }

            $escalationRepository->create(new Escalation(
                null,
                $escalation->position,
                $escalation->condition,
                $recipients,
                $ruleId
            ));
        }

        return $ruleId;
    }
}
