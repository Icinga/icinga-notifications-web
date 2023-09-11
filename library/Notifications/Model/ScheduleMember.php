<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class ScheduleMember extends Model
{
    public function getTableName()
    {
        return 'schedule_member';
    }

    public function getKeyName()
    {
        return [
            'schedule_id',
            'timeperiod_id'
        ];
    }

    public function getColumns()
    {
        return [
            'contact_id',
            'contactgroup_id',
            'membership_hash'
        ];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary([
            'membership_hash'
        ]));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasOne('timeperiod', Timeperiod::class)
            ->setCandidateKey('timeperiod_id')
            ->setForeignKey('id');
        $relations->belongsTo('schedule', Schedule::class)
            ->setCandidateKey('schedule_id')
            ->setForeignKey('id');
        $relations->belongsTo('contact', Contact::class)
            ->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)
            ->setJoinType('LEFT');
    }
}
