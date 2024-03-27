<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

/**
 *
 * @property int $id
 * @property int $incident_id
 * @property int $event_id
 * @property int $rule_id
 * @property int $rule_escalation_id
 * @property DateTime $time
 * @property int $contact_id
 * @property int $schedule_id
 * @property int $contactgroup_id
 * @property int $channel_id
 * @property int $caused_by_incident_history_id
 * @property string $message
 * @property Incident $incident
 */
class IncidentHistory extends Model
{
    public function getTableName(): string
    {
        return 'incident_history';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    /**
     * @return array<string>
     */
    public function getColumns(): array
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
            'channel_id',
            'caused_by_incident_history_id',
            'new_severity',
            'old_severity',
            'new_recipient_role',
            'old_recipient_role',
            'message'
        ];
    }

    /**
     * @return array<string>
     */
    public function getColumnDefinitions(): array
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
            'channel_id'                    => t('Channel ID'),
            'new_recipient_role'            => t('New Recipient Role'),
            'old_recipient_role'            => t('Old Recipient Role'),
            'message'                       => t('Message')
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['time']));
    }

    /**
     * @return array<string>
     */
    public function getDefaultSort(): array
    {
        return ['incident_history.time desc'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('incident', Incident::class);

        $relations->belongsTo('event', Event::class)->setJoinType('LEFT');
        $relations->belongsTo('contact', Contact::class)->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)->setJoinType('LEFT');
        $relations->belongsTo('rule', Rule::class)->setJoinType('LEFT');
        $relations->belongsTo('rule_escalation', RuleEscalation::class)->setJoinType('LEFT');
        $relations->belongsTo('channel', Channel::class)->setJoinType('LEFT');
    }
}
