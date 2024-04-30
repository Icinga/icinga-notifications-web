<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Timeperiod
 *
 * @property int $id
 * @property ?int $owned_by_rotation_id
 *
 * @property Query|Rotation $rotation
 * @property Query|TimeperiodEntry $timeperiod_entry
 */
class Timeperiod extends Model
{
    public function getTableName()
    {
        return 'timeperiod';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'owned_by_schedule_id',
            'changed_at',
            'deleted'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('rotation', Rotation::class)
            ->setCandidateKey('owned_by_rotation_id')
            ->setJoinType('LEFT');
        $relations->hasMany('timeperiod_entry', TimeperiodEntry::class)
            ->setJoinType('LEFT');
    }
}
