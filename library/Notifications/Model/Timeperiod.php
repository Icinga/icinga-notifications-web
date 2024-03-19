<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

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
            'owned_by_schedule_id'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsToMany('schedule', Schedule::class)
            ->through(ScheduleMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('schedule_member', ScheduleMember::class)
            ->setJoinType('LEFT');
        $relations->hasOne('parent', Schedule::class)
            ->setCandidateKey('owned_by_schedule_id')
            ->setJoinType('LEFT');
        $relations->hasMany('timeperiod_entry', TimeperiodEntry::class)
            ->setJoinType('LEFT');
    }
}
