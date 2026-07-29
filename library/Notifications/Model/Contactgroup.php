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
 * Contact group
 *
 * @property int $id
 * @property string $name
 * @property DateTime $changed_at
 * @property bool $deleted
 * @property string $external_uuid
 *
 * @property Query<Contact>|Collection<Contact> $contact
 * @property Query<Rotation>|Collection<Rotation> $rotation
 * @property Query<RuleEscalation>|Collection<RuleEscalation> $rule_escalation
 * @property Query<ContactgroupMember>|Collection<ContactgroupMember> $contactgroup_member
 * @property Query<RuleEscalationRecipient>|Collection<RuleEscalationRecipient> $rule_escalation_recipient
 * @property Query<IncidentHistory>|Collection<IncidentHistory> $incident_history
 */
class Contactgroup extends Model
{
    public function getTableName(): string
    {
        return 'contactgroup';
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
            'deleted',
            'external_uuid'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'name'          => t('Name'),
            'changed_at'    => t('Changed At'),
            'external_uuid' => t('UUID')
        ];
    }

    public function getSearchColumns(): array
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
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class);
        $relations->hasMany('contactgroup_member', ContactgroupMember::class);
        $relations
            ->belongsToMany('contact', Contact::class)
            ->through(ContactgroupMember::class)
            ->setJoinType('LEFT');
        $relations->belongsToMany('rotation', Rotation::class)
            ->through(RotationMember::class)
            ->setJoinType('LEFT');
        $relations->belongsToMany('rule_escalation', RuleEscalation::class)
            ->through(RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
    }

    public function createVisibilityFilter(Filter\Chain $filter): void
    {
        $filter->add(Filter::equal('deleted', 'n'));
    }
}
