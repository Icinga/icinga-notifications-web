<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * SkippedNotificationHistory
 *
 * @property int $id
 * @property int $notification_history_id
 * @property int $rule_id
 * @property int $rule_escalation_id
 * @property ?int $contactgroup_id
 * @property ?int $schedule_id
 *
 * @property Query<NotificationHistory>|NotificationHistory $notification_history
 * @property Query<Rule>|Rule $rule
 * @property Query<RuleEscalation>|RuleEscalation $rule_escalation
 * @property Query<Contactgroup>|Contactgroup $contactgroup
 * @property Query<Schedule>|Schedule $schedule
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
        $relations->belongsTo('notification_history', NotificationHistory::class);
        $relations->belongsTo('rule', Rule::class)->setJoinType('LEFT');
        $relations->belongsTo('rule_escalation', RuleEscalation::class)->setJoinType('LEFT');

        $relations->belongsTo('contactgroup', Contactgroup::class)->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)->setJoinType('LEFT');
    }
}
