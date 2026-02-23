<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
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
 * RotationMember
 *
 * @property int $id
 * @property int $rotation_id
 * @property ?int $contact_id
 * @property ?int $contactgroup_id
 * @property ?int $position
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Rotation $rotation
 * @property Query|Contact $contact
 * @property Query|Contactgroup $contactgroup
 * @property Query|TimeperiodEntry $shift
 */
class RotationMember extends Model
{
    public function getTableName(): string
    {
        return 'rotation_member';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'rotation_id',
            'contact_id',
            'contactgroup_id',
            'position',
            'changed_at',
            'deleted'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('rotation', Rotation::class);
        $relations->belongsTo('contact', Contact::class)
            ->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)
            ->setJoinType('LEFT');
        $relations->hasMany('shift', TimeperiodEntry::class);
    }
}
