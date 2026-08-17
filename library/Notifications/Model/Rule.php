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
 * @property string $name
 * @property int $source_id
 * @property ?int $timeperiod_id
 * @property ?string $object_filter
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query<Source>|Source $source
 * @property Query<RuleEscalation>|Collection<RuleEscalation> $rule_escalation
 * @property Query<Incident>|Collection<Incident> $incident
 * @property Query<IncidentHistory>|Collection<IncidentHistory> $incident_history
 * @property Query<SkippedNotificationHistory>|Collection<SkippedNotificationHistory> $skipped_notification_history
 */
class Rule extends Model
{
    public function getTableName(): string
    {
        return 'rule';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'source_id',
            'timeperiod_id',
            'object_filter',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'name'          => t('Name'),
            'source_id'     => t('Source ID'),
            'timeperiod_id' => t('Timeperiod ID'),
            'object_filter' => t('Object Filter'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): array
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('source', Source::class);
        $relations->hasMany('rule_escalation', RuleEscalation::class)
            ->setJoinType('LEFT');

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_history', IncidentHistory::class)->setJoinType('LEFT');
        $relations->hasMany('skipped_notification_history', SkippedNotificationHistory::class)
            ->setJoinType('LEFT');
    }

    public function createVisibilityFilter(Filter\Chain $filter): void
    {
        $filter->add(Filter::equal('deleted', 'n'));
    }
}
