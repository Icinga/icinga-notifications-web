<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Model;
use Icinga\Module\Notifications\Common\NotificationTransmissionReason;
use Icinga\Module\Notifications\Common\NotificationTransmissionState;
use ipl\Orm\Behavior\EnumCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Sql\Select;

/**
 * NotificationHistory
 *
 * @property int $id
 * @property ?int $incident_id
 * @property int $rule_id
 * @property int $rule_escalation_id
 * @property int $contact_id
 * @property ?int $contactgroup_id
 * @property int $channel_id
 * @property ?int $schedule_id
 * @property ?string $message
 * @property NotificationTransmissionReason $reason
 * @property NotificationTransmissionState $state
 * @property DateTime $triggered_at
 *
 * @property Query|Incident $incident
 * @property Query|Rule $rule
 * @property Query|RuleEscalation $rule_escalation
 * @property Query|Contact $contact
 * @property Query|Contactgroup $contactgroup
 * @property Query|Channel $channel
 * @property Query|Schedule $schedule
 */
class NotificationHistory extends Model
{
    public function getTableName(): string
    {
        return 'notification_history';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'incident_id',
            'rule_id',
            'rule_escalation_id',
            'contact_id',
            'contactgroup_id',
            'channel_id',
            'schedule_id',
            'message',
            'reason',
            'state',
            'triggered_at'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'incident_id'           => t('Incident Id'),
            'rule_id'               => t('Rule Id'),
            'rule_escalation_id'    => t('Rule Escalation Id'),
            'contact_id'            => t('Contact Id'),
            'contactgroup_id'       => t('Contact Group Id'),
            'channel_id'            => t('Channel ID'),
            'schedule_id'           => t('Schedule Id'),
            'message'               => t('Message'),
            'reason'                => t('Reason'),
            'state'                 => t('State'),
            'triggered_at'          => t('Triggered At')
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['triggered_at']));
        $behaviors->add(new EnumCast(NotificationTransmissionState::class, ['state']));
        $behaviors->add(new EnumCast(NotificationTransmissionReason::class, ['reason']));
    }

    public function getDefaultSort(): array
    {
        return ['notification_history.triggered_at desc'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rule', Rule::class);
        $relations->belongsTo('rule_escalation', RuleEscalation::class);
        $relations->belongsTo('contact', Contact::class);

        $relations->belongsTo('incident', Incident::class)->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)->setJoinType('LEFT');
        $relations->belongsTo('channel', Channel::class)->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)->setJoinType('LEFT');
    }

    public static function on(Connection $db): Query
    {
        $query = parent::on($db);

        $query->on(Query::ON_SELECT_ASSEMBLED, function (Select $select) use ($query) {
            if (isset($query->getUtilize()['notification_history.incident.object.object_id_tag'])) {
                Database::registerGroupBy($query, $select);
            }
        });

        return $query;
    }
}
