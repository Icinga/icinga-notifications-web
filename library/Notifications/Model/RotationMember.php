<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

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
 * @property int $position
 *
 * @property Query|Rotation $rotation
 * @property Query|Contact $contact
 * @property Query|Contactgroup $contactgroup
 * @property Query|TimeperiodEntry $shift
 */
class RotationMember extends Model
{
    public function getTableName()
    {
        return 'rotation_member';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'rotation_id',
            'contact_id',
            'contactgroup_id',
            'position'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('rotation', Rotation::class);
        $relations->belongsTo('contact', Contact::class)
            ->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)
            ->setJoinType('LEFT');
        $relations->hasMany('shift', TimeperiodEntry::class);
    }
}
