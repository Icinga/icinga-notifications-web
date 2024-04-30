<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;
use Recurr\Frequency;
use Recurr\Rule;

/**
 * TimeperiodEntry
 *
 * @property int $id
 * @property int $timeperiod_id
 * @property int $rotation_member_id
 * @property DateTime $start_time
 * @property DateTime $end_time
 * @property ?DateTime $until_time
 * @property string $timezone
 * @property ?string $rrule
 *
 * @property Timeperiod $timeperiod
 * @property RotationMember $member
 */
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
            'rotation_member_id',
            'start_time',
            'end_time',
            'until_time',
            'timezone',
            'rrule',
            'changed_at',
            'deleted'
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
            'until_time',
            'changed_at'
        ]));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('timeperiod', Timeperiod::class);
        $relations->belongsTo('member', RotationMember::class);
    }

    /**
     * Convert the entry to a RecurrenceRule
     *
     * @return Rule
     */
    public function toRecurrenceRule(): Rule
    {
        $rrule = new Rule($this->rrule, $this->start_time, null, $this->timezone);

        if ($this->rrule === null) {
            $rrule->setFreq(Frequency::YEARLY);
            $rrule->setUntil($this->start_time);
        }

        return $rrule;
    }
}
