<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use Icinga\Module\Noma\Model\Behavior\Timestamp;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class IncidentHistory extends Model
{
    public function getTableName()
    {
        return 'incident_history';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'incident_id',
            'rule_escalation_id',
            'time',
            'message'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'incident_id'           => t('Incident Id'),
            'rule_escalation_id'    => t('Rule Escalation Id'),
            'time'                  => t('Time'),
            'message'               => t('Message')
        ];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Timestamp(['time']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('incident', Incident::class);
    }
}
