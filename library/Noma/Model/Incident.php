<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class Incident extends Model
{
    public function getTableName()
    {
        return 'incident';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'object_id',
            'started_at',
            'recovered_at',
            'severity'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'object_id'     => t('Object Id'),
            'started_at'    => t('Started At'),
            'recovered_at'  => t('Recovered At'),
            'severity'      => t('Severity')
        ];
    }

    public function getSearchColumns()
    {
        return ['severity'];
    }

    public function getDefaultSort()
    {
        return ['incident.severity desc, incident.started_at desc'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['object_id']));
        $behaviors->add(new MillisecondTimestamp([
            'started_at',
            'recovered_at'
        ]));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('object', Objects::class);

        $relations
            ->belongsToMany('event', Event::class)
            ->through('incident_event');

        $relations->belongsToMany('contact', Contact::class)
            ->through('incident_contact');

        $relations->hasMany('incident_contact', IncidentContact::class);
        $relations->hasMany('incident_history', IncidentHistory::class);

        $relations
            ->belongsToMany('rule', Rule::class)
            ->through('incident_rule');

        $relations
            ->belongsToMany('rule_escalation', RuleEscalation::class)
            ->through('incident_rule_escalation_state')
            ->setJoinType('LEFT');
    }
}
