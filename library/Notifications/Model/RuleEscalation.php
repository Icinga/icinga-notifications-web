<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Stdlib\Filter;

/**
 * @property int $id
 * @property int $rule_id
 * @property ?int $position
 * @property ?string $condition
 * @property ?string $name
 * @property ?string $fallback_for
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query<Rule>|Rule $rule
 * @property Query<Incident>|Collection<Incident> $incident
 * @property Query<Contact>|Collection<Contact> $contact
 * @property Query<Contactgroup>|Collection<Contactgroup> $contactgroup
 * @property Query<Schedule>|Collection<Schedule> $schedule
 * @property Query<RuleEscalationRecipient>|Collection<RuleEscalationRecipient> $rule_escalation_recipient
 * @property Query<IncidentHistory>|Collection<IncidentHistory> $incident_history
 * @property Query<SkippedNotificationHistory>|Collection<SkippedNotificationHistory> $skipped_notification_history
 */
class RuleEscalation extends Model
{
    public function getTableName(): string
    {
        return 'rule_escalation';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'rule_id',
            'position',
            'condition',
            'name',
            'fallback_for',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'rule_id'       => t('Rule ID'),
            'position'      => t('Position'),
            'condition'     => t('Condition'),
            'name'          => t('Name'),
            'fallback_for'  => t('Fallback For'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): array
    {
        return ['position'];
    }


    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rule', Rule::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule_escalation_state');

        $relations->belongsToMany('contact', Contact::class)
            ->through(RuleEscalationRecipient::class)
            ->setTargetForeignKey('contact_id')
            ->setJoinType('LEFT');
        $relations->belongsToMany('contactgroup', Contactgroup::class)
            ->through(RuleEscalationRecipient::class)
            ->setTargetForeignKey('contactgroup_id')
            ->setJoinType('LEFT');
        $relations->belongsToMany('schedule', Schedule::class)
            ->through(RuleEscalationRecipient::class)
            ->setTargetForeignKey('schedule_id')
            ->setJoinType('LEFT');

        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class)
            ->setJoinType('LEFT');
        $relations->hasMany('skipped_notification_history', SkippedNotificationHistory::class)
            ->setJoinType('LEFT');
    }

    public function createVisibilityFilter(Filter\Chain $filter): void
    {
        $filter->add(Filter::equal('deleted', 'n'));
    }
}
