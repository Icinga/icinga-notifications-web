<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class Rule extends Model
{
    public function getTableName()
    {
        return 'rule';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name',
            'timeperiod_id',
            'object_filter',
            'is_active',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'name'          => t('Name'),
            'timeperiod_id' => t('Timeperiod ID'),
            'object_filter' => t('Object Filter'),
            'is_active'     => t('Is Active'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('rule_escalation', RuleEscalation::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_history', IncidentHistory::class)->setJoinType('LEFT');
    }
}
