<?php

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Schedule extends Model
{
    public function getTableName()
    {
        return 'schedule';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsToMany('timeperiod', Timeperiod::class)
            ->through(ScheduleMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('member', ScheduleMember::class)
            ->setJoinType('LEFT');
    }
}
