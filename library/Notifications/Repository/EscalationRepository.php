<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use LogicException;

final class EscalationRepository
{
    /**
     * Create a `EscalationRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the escalation with the given ID
     *
     * @param int $id
     *
     * @return ?RuleEscalation
     */
    public function find(int $id): ?RuleEscalation
    {
        return RuleEscalation::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->filter(Filter::equal('deleted', false))
            ->first();
    }

    /**
     * Store a new escalation
     *
     * @param Escalation $escalation
     *
     * @return int The escalation's ID
     */
    public function create(Escalation $escalation): int
    {
        $model = (new RuleEscalation())->setNew();
        $model->rule_id = $escalation->ruleId ?? throw new LogicException('Missing rule ID');
        $model->position = $escalation->position;
        $model->condition = $escalation->condition;

        $recipients = [];
        foreach ($escalation->recipients as $recipient) {
            $recipientModel = (new RuleEscalationRecipient())->setNew();
            $typeId = match ($recipient->type) {
                'contact' => 'contact_id',
                'contact_group' => 'contactgroup_id',
                'schedule' => 'schedule_id'
            };

            $recipientModel->{$typeId} = $recipient->recipientId;
            $recipientModel->channel_id = $recipient->channelId;

            $recipients[] = $recipientModel;
        }

        $model->rule_escalation_recipient = Collection::create(RuleEscalationRecipient::class, $recipients);

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given escalation
     *
     * @param Escalation $escalation
     *
     * @return void
     *
     * @throws InvalidArgumentException if the escalation does not exist
     */
    public function update(Escalation $escalation): void
    {
        $model = $this->find($escalation->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update an escalation that does not exist');
        }

        $model->position = $escalation->position;
        $model->condition = $escalation->condition;

        $recipientsToKeep = [];
        foreach ($escalation->recipients as $recipient) {
            if (isset($recipient->id)) {
                $recipientsToKeep[$recipient->id] = $recipient;
            }
        }

        $model->rule_escalation_recipient->query()->filter(Filter::equal('deleted', false));
        foreach ($model->rule_escalation_recipient as $recipientModel) {
            if (isset($recipientsToKeep[$recipientModel->id])) {
                $recipient = $recipientsToKeep[$recipientModel->id];
                [$typeId, $oppositeKeys] = match ($recipient->type) {
                    'contact' => ['contact_id', ['contactgroup_id', 'schedule_id']],
                    'contact_group' => ['contactgroup_id', ['contact_id', 'schedule_id']],
                    'schedule' => ['schedule_id', ['contact_id', 'contactgroup_id']]
                };
                $recipientModel->{$typeId} = $recipient->recipientId;
                $recipientModel->channel_id = $recipient->channelId;
                foreach ($oppositeKeys as $oppositeKey) {
                    $recipientModel->{$oppositeKey} = null;
                }
            } else {
                $model->rule_escalation_recipient->detach($recipientModel);
            }
        }

        foreach ($escalation->recipients as $recipient) {
            if (isset($recipient->id)) {
                continue;
            }

            $recipientModel = (new RuleEscalationRecipient())->setNew();
            $typeId = match ($recipient->type) {
                'contact' => 'contact_id',
                'contact_group' => 'contactgroup_id',
                'schedule' => 'schedule_id'
            };

            $recipientModel->{$typeId} = $recipient->recipientId;
            $recipientModel->channel_id = $recipient->channelId;

            $model->rule_escalation_recipient->attach($recipientModel);
        }

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Delete the escalation with the given ID
     *
     * @param int $id
     *
     * @return void
     *
     * @throws InvalidArgumentException if the escalation does not exist
     */
    public function delete(int $id): void
    {
        $escalation = $this->find($id)?->setNew(false);
        if ($escalation === null) {
            throw new InvalidArgumentException('Cannot delete an escalation that does not exist');
        }

        $escalation->position = null;
        $escalation->rule_escalation_recipient = [];
        $escalation->delete();

        (new EntityManager($this->db))->save($escalation);
    }
}
