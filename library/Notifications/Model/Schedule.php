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
 * @property string $timezone
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query<Rotation>|Collection<Rotation> $rotation
 * @property Query<RuleEntryRecipient>|Collection<RuleEntryRecipient> $rule_entry_recipient
 * @property Query<IncidentHistory>|Collection<IncidentHistory> $incident_history
 * @property Query<RuleEntry>|Collection<RuleEntry> $rule_entry
 * @property Query<NotificationHistory>|Collection<NotificationHistory> $notification_history
 */
class Schedule extends Model
{
    public function getTableName(): string
    {
        return 'schedule';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'changed_at',
            'timezone',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'name'          => t('Name'),
            'changed_at'    => t('Changed At'),
            'timezone'      => t('Timezone')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): string
    {
        return 'name';
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('rotation', Rotation::class)
            ->setJoinType('LEFT');
        $relations->hasMany('rule_entry_recipient', RuleEntryRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class)
            ->setJoinType('LEFT');

        $relations->belongsToMany('rule_entry', RuleEntry::class)
            ->through(RuleEntryRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('notification_history', NotificationHistory::class)
            ->setJoinType('LEFT');
    }

    public function createVisibilityFilter(Filter\Chain $filter): void
    {
        $filter->add(Filter::equal('deleted', 'n'));
    }
}
