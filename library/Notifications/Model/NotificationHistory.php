<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Model;
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
 * @property int $incident_history_id
 * @property string $event_id
 * @property int $contact_id
 * @property ?int $contactgroup_id
 * @property ?int $schedule_id
 * @property int $channel_id
 * @property int $incident_id
 * @property string $message
 * @property NotificationTransmissionState $state
 * @property DateTime $triggered_at
 * @property int $source_id
 *
 * @property Query<IncidentHistory>|IncidentHistory $incident_history
 * @property Query<Incident>|Incident $incident
 * @property Query<Contact>|Contact $contact
 * @property Query<Contactgroup>|Contactgroup $contactgroup
 * @property Query<Channel>|Channel $channel
 * @property Query<Schedule>|Schedule $schedule
 * @property Query<Source>|Source $source
 * @property Query<SkippedNotificationHistory>|Collection<SkippedNotificationHistory> $skipped
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
            'incident_history_id',
            'event_id',
            'contact_id',
            'contactgroup_id',
            'schedule_id',
            'channel_id',
            'incident_id',
            'message',
            'state',
            'triggered_at',
            'source_id'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'incident_history_id'   => t('Incident History Id'),
            'contact_id'            => t('Contact Id'),
            'contactgroup_id'       => t('Contact Group Id'),
            'schedule_id'           => t('Schedule Id'),
            'channel_id'            => t('Channel ID'),
            'incident_id'           => t('Incident Id'),
            'message'               => t('Message'),
            'state'                 => t('State'),
            'triggered_at'          => t('Triggered At'),
            'source_id'             => t('Source Id')
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['triggered_at']));
        $behaviors->add(new EnumCast(NotificationTransmissionState::class, ['state']));
    }

    public function getDefaultSort(): array
    {
        return ['notification_history.triggered_at desc'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('incident_history', IncidentHistory::class);
        $relations->belongsTo('incident', Incident::class);
        $relations->belongsTo('contact', Contact::class);
        $relations->belongsTo('channel', Channel::class);
        $relations->belongsTo('source', Source::class);

        $relations->belongsTo('contactgroup', Contactgroup::class)->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)->setJoinType('LEFT');

        $relations->hasMany('skipped', SkippedNotificationHistory::class)
            ->setJoinType('LEFT');
    }

    /**
     * @inheritDoc
     */
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
