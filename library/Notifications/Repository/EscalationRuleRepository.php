<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\EntityManager;
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

        $escalationRepository = new EscalationRepository($this->db);
        foreach ($rule->escalations as $escalation) {
            // TODO: Once modals are used, this is obsolete
            $escalation->ruleId = $model->id;
            $escalationRepository->create($escalation);
        }

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
        $model->source_type = $rule->sourceType;
        $model->object_filter = $rule->objectFilter;

        (new EntityManager($this->db))->save($model);

        // TODO: Once modals are used, this is obsolete
        $escalationRepository = new EscalationRepository($this->db);

        $escalationsToKeep = [];
        foreach ($rule->escalations as $escalation) {
            if (isset($escalation->id)) {
                $escalationsToKeep[] = $escalation->id;
            }
        }

        $model->rule_escalation->query()->columns('id');
        foreach ($model->rule_escalation as $escalationModel) {
            if (! in_array($escalationModel->id, $escalationsToKeep, true)) {
                $escalationRepository->delete($escalationModel->id);
            }
        }

        foreach ($rule->escalations as $escalation) {
            if (! isset($escalation->id)) {
                $escalation->ruleId ??= $model->id;
                $escalationRepository->create($escalation);
            } else {
                $escalationRepository->update($escalation);
            }
        }
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
}
