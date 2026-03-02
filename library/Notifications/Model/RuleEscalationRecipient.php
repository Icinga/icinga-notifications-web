<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $id
 * @property int $rule_escalation_id
 * @property ?int $contact_id
 * @property ?int $contactgroup_id
 * @property ?int $schedule_id
 * @property ?int $channel_id
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|RuleEscalation $rule_escalation
 * @property Query|Contact $contact
 * @property Query|Schedule $schedule
 * @property Query|Contactgroup $contactgroup
 * @property Query|Channel $channel
 */
class RuleEscalationRecipient extends Model
{
    public function getTableName(): string
    {
        return 'rule_escalation_recipient';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'rule_escalation_id',
            'contact_id',
            'contactgroup_id',
            'schedule_id',
            'channel_id',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'rule_escalation_id' => t('Rule Escalation ID'),
            'contact_id'         => t('Contact ID'),
            'contactgroup_id'    => t('Contactgroup ID'),
            'schedule_id'        => t('Schedule ID'),
            'channel_id'         => t('Channel ID'),
            'changed_at'         => t('Changed At')
        ];
    }

    public function getDefaultSort(): array
    {
        return ['rule_escalation_id'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rule_escalation', RuleEscalation::class);
        $relations->belongsTo('contact', Contact::class);
        $relations->belongsTo('schedule', Schedule::class);
        $relations->belongsTo('contactgroup', Contactgroup::class);
        $relations->belongsTo('channel', Channel::class);
    }

    /**
     * Get the recipient model
     *
     * @return Contact|Contactgroup|Schedule|null
     */
    public function getRecipient(): ?Model
    {
        $recipientModel = null;
        if ($this->contact_id) {
            $recipientModel = $this->contact->first();
        }

        if ($this->contactgroup_id) {
            $recipientModel = $this->contactgroup->first();
        }

        if ($this->schedule_id) {
            $recipientModel = $this->schedule->first();
        }

        return $recipientModel;
    }
}
