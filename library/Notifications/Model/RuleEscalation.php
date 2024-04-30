<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class RuleEscalation extends Model
{
    public function getTableName()
    {
        return 'rule_escalation';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
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

    public function getColumnDefinitions()
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

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return ['position'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations)
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
