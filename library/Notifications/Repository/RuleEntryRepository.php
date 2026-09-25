<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\RuleEntry as RuleEntryData;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Model\RuleEntryRecipient;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

final class RuleEntryRepository
{
    /**
     * Create a `RuleEntryRepository` instance
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
     * @return ?RuleEntry
     */
    public function find(int $id): ?RuleEntry
    {
        return RuleEntry::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();
    }

    /**
     * Store a new escalation
     *
     * @param RuleEntryData $entry
     *
     * @return int The escalation's ID
     */
    public function create(RuleEntryData $entry): int
    {
        $model = (new RuleEntry())->setNew();
        $model->rule_id = $entry->ruleId;
        $model->position = $entry->position;
        $model->condition = $entry->condition;

        $recipients = [];
        foreach ($entry->recipients as $recipient) {
            $recipientModel = (new RuleEntryRecipient())->setNew();
            $typeId = match ($recipient->type) {
                'contact' => 'contact_id',
                'contact_group' => 'contactgroup_id',
                'schedule' => 'schedule_id'
            };

            $recipientModel->{$typeId} = $recipient->recipientId;
            $recipientModel->channel_id = $recipient->channelId;

            $recipients[] = $recipientModel;
        }

        $model->rule_entry_recipient = Collection::create(RuleEntryRecipient::class, $recipients);

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given escalation
     *
     * @param RuleEntryData $entry
     *
     * @return void
     *
     * @throws InvalidArgumentException if the escalation does not exist
     */
    public function update(RuleEntryData $entry): void
    {
        $model = $this->find($entry->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update an escalation that does not exist');
        }

        $model->position = $entry->position;
        $model->condition = $entry->condition;

        $recipientsToKeep = [];
        foreach ($entry->recipients as $recipient) {
            if (isset($recipient->id)) {
                $recipientsToKeep[$recipient->id] = $recipient;
            }
        }

        foreach ($model->rule_entry_recipient as $recipientModel) {
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
                $model->rule_entry_recipient->detach($recipientModel);
            }
        }

        foreach ($entry->recipients as $recipient) {
            if (isset($recipient->id)) {
                continue;
            }

            $recipientModel = (new RuleEntryRecipient())->setNew();
            $typeId = match ($recipient->type) {
                'contact' => 'contact_id',
                'contact_group' => 'contactgroup_id',
                'schedule' => 'schedule_id'
            };

            $recipientModel->{$typeId} = $recipient->recipientId;
            $recipientModel->channel_id = $recipient->channelId;

            $model->rule_entry_recipient->attach($recipientModel);
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

        $entityManager = new EntityManager($this->db);
        $freedPosition = $escalation->position;

        $escalation->position = null;
        $escalation->rule_entry_recipient = [];
        $escalation->delete();

        $entityManager->save($escalation);

        $siblings = RuleEntry::on($this->db)
            ->columns(['id', 'position'])
            ->filter(Filter::equal('rule_id', $escalation->rule_id))
            ->filter(Filter::greaterThan('position', $freedPosition))
            ->orderBy('position', SORT_ASC);
        foreach ($siblings as $sibling) {
            $sibling->setNew(false);
            $sibling->position -= 1;
            $entityManager->save($sibling);
        }
    }
}
