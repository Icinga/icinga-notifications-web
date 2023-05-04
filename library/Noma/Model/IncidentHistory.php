<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Behavior\MillisecondTimestamp;
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
            'event_id',
            'rule_id',
            'rule_escalation_id',
            'time',
            'type',
            'contact_id',
            'schedule_id',
            'contactgroup_id',
            'caused_by_incident_history_id',
            'channel_type',
            'new_severity',
            'old_severity',
            'new_recipient_role',
            'old_recipient_role',
            'message'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'incident_id'                   => t('Incident Id'),
            'event_id'                      => t('Event Id'),
            'rule_escalation_id'            => t('Rule Escalation Id'),
            'time'                          => t('Time'),
            'type'                          => t('Type'),
            'new_severity'                  => t('New Severity'),
            'old_severity'                  => t('Old Severity'),
            'contact_id'                    => t('Contact Id'),
            'schedule_id'                   => t('Schedule Id'),
            'contactgroup_id'               => t('Contact Group Id'),
            'caused_by_incident_history_id' => t('Caused By Incident History Id'),
            'channel_type'                  => t('Channel Type'),
            'new_recipient_role'            => t('New Recipient Role'),
            'old_recipient_role'            => t('Old Recipient Role'),
            'message'                       => t('Message')
        ];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp(['time']));
    }

    public function getDefaultSort()
    {
        return ['incident_history.time desc'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('incident', Incident::class);

        $relations->belongsTo('event', Event::class)->setJoinType('LEFT');
        $relations->belongsTo('contact', Contact::class)->setJoinType('LEFT');
    }
}
