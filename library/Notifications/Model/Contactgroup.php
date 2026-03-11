<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Contact group
 *
 * @property int $id
 * @property string $name
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Contact $contact
 * @property Query|ContactgroupMember $contactgroup_member
 * @property Query|RuleEscalationRecipient $rule_escalation_recipient
 * @property Query|IncidentHistory $incident_history
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
            ->through('contactgroup_member')
            ->setJoinType('LEFT');
    }
}
