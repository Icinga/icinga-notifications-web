<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * NotificationHistory
 *
 * @property int $id
 * @property int $notification_history_id
 * @property int $event_id
 * @property int $rule_id
 * @property int $rule_escalation_id
 * @property ?int $contactgroup_id
 * @property int $channel_id
 * @property ?int $schedule_id
 * @property DateTime $triggered_at
 *
 * @property Query|Incident $incident
 * @property Query|Rule $rule
 * @property Query|RuleEscalation $rule_escalation
 * @property Query|Contactgroup $contactgroup
 * @property Query|Channel $channel
 * @property Query|Schedule $schedule
 */
class SkippedNotificationHistory extends Model
{
    public function getTableName(): string
    {
        return 'skipped_notification_history';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'notification_history_id',
            'rule_id',
            'rule_escalation_id',
            'contactgroup_id',
            'schedule_id'
        ];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rule', Rule::class);
        $relations->belongsTo('rule_escalation', RuleEscalation::class);
        $relations->belongsTo('notification_history', NotificationHistory::class);

        $relations->belongsTo('contactgroup', Contactgroup::class)->setJoinType('LEFT');
        $relations->belongsTo('channel', Channel::class)->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)->setJoinType('LEFT');
    }
}
