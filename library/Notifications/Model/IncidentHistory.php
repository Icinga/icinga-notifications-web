<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Sql\Select;

/**
 * IncidentHistory
 *
 * @property int $id
 * @property int $incident_id
 * @property ?int $event_id
 * @property ?int $rule_id
 * @property ?int $rule_escalation_id
 * @property DateTime $time
 * @property string $type
 * @property ?int $contact_id
 * @property ?int $schedule_id
 * @property ?int $contactgroup_id
 * @property ?int $channel_id
 * @property ?string $new_severity
 * @property ?string $old_severity
 * @property ?string $new_recipient_role
 * @property ?string $old_recipient_role
 * @property ?string $message
 * @property ?string $notification_state
 * @property ?DateTime $sent_at
 *
 * @property Query|Incident $incident
 * @property Query|Event $event
 * @property Query|Contact $contact
 * @property Query|Contactgroup $contactgroup
 * @property Query|Schedule $schedule
 * @property Query|Rule $rule
 * @property Query|RuleEscalation $rule_escalation
 * @property Query|Channel $channel
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
            'new_severity',
            'old_severity',
            'new_recipient_role',
            'old_recipient_role',
            'message',
            'notification_state',
            'sent_at'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'incident_id'        => t('Incident Id'),
            'event_id'           => t('Event Id'),
            'rule_escalation_id' => t('Rule Escalation Id'),
            'time'               => t('Time'),
            'type'               => t('Type'),
            'new_severity'       => t('New Severity'),
            'old_severity'       => t('Old Severity'),
            'contact_id'         => t('Contact Id'),
            'schedule_id'        => t('Schedule Id'),
            'contactgroup_id'    => t('Contact Group Id'),
            'channel_id'         => t('Channel ID'),
            'new_recipient_role' => t('New Recipient Role'),
            'old_recipient_role' => t('Old Recipient Role'),
            'message'            => t('Message')
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['time', 'sent_at']));
    }

    public function getDefaultSort(): array
    {
        return ['incident_history.time desc, incident_history.type desc'];
    }

    public static function on(Connection $db): Query
    {
        $query = parent::on($db);

        $query->on(Query::ON_SELECT_ASSEMBLED, function (Select $select) use ($query) {
            if (isset($query->getUtilize()['incident_history.incident.object.object_id_tag'])) {
                Database::registerGroupBy($query, $select);
            }
        });

        return $query;
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

    /**
     * Transform the given notification state into a translatable message.
     *
     * @param string $state
     *
     * @return string
     */
    public static function translateNotificationState(string $state): string
    {
        switch ($state) {
            case 'sent':
                return t('sent', 'notifications.transmission.state');
            case 'failed':
                return t('failed', 'notifications.transmission.state');
            case 'pending':
                return t('pending', 'notifications.transmission.state');
            case 'suppressed':
                return t('suppressed', 'notifications.transmission.state');
            default:
                return t('unknown', 'notifications.transmission.state');
        }
    }
}
