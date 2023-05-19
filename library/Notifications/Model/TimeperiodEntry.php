<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class TimeperiodEntry extends Model
{
    public function getTableName()
    {
        return 'timeperiod_entry';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'timeperiod_id',
            'start_time',
            'end_time',
            'until_time',
            'timezone',
            'rrule',
            'frequency',
            'description'
        ];
    }

    public function getDefaultSort()
    {
        return ['start_time asc', 'end_time asc'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp([
            'start_time',
            'end_time',
            'until_time'
        ]));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('timeperiod', Timeperiod::class);
    }
}
