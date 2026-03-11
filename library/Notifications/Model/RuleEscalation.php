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
 * @property int $rule_id
 * @property ?int $position
 * @property ?string $condition
 * @property ?string $name
 * @property ?string $fallback_for
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Rule $rule
 * @property Query|Incident $incident
 * @property Query|Contact $contact
 * @property Query|RuleEscalationRecipient $rule_escalation_recipient
 * @property Query|IncidentHistory $incident_history
 */
class RuleEscalation extends Model
{
    public function getTableName(): string
    {
        return 'rule_escalation';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'rule_id',
            'position',
            'condition',
            'name',
            'fallback_for',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'rule_id'       => t('Rule ID'),
            'position'      => t('Position'),
            'condition'     => t('Condition'),
            'name'          => t('Name'),
            'fallback_for'  => t('Fallback For'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): array
    {
        return ['position'];
    }


    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rule', Rule::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule_escalation_state');

        $relations
            ->belongsToMany('contact', Contact::class)
            ->through('rule_escalation_recipient')
            ->setJoinType('LEFT');

        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class)
            ->setJoinType('LEFT');
    }
}
