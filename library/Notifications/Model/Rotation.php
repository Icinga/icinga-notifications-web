<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Util\Json;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Contract\RetrieveBehavior;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Rotation
 *
 * @property int $id
 * @property int $schedule_id
 * @property int $priority
 * @property string $name
 * @property string $mode
 * @property string|array $options
 * @property string $first_handoff
 * @property DateTime $actual_handoff
 *
 * @property Query|Schedule $schedule
 * @property Query|RotationMember $member
 * @property Query|Timeperiod $timeperiod
 */
class Rotation extends Model
{
    public function getTableName()
    {
        return 'rotation';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'schedule_id',
            'priority',
            'name',
            'mode',
            'options',
            'first_handoff',
            'actual_handoff'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'schedule_id' => t('Schedule'),
            'priority' => t('Priority'),
            'name' => t('Name'),
            'mode' => t('Mode'),
            'first_handoff' => t('First Handoff'),
            'actual_handoff' => t('Actual Handoff')
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('schedule', Schedule::class);

        $relations->hasMany('member', RotationMember::class);

        $relations->hasOne('timeperiod', Timeperiod::class)
            ->setForeignKey('owned_by_rotation_id');
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp([
            'actual_handoff'
        ]));
        $behaviors->add(new class implements RetrieveBehavior {
            public function retrieve(Model $model): void
            {
                /** @var Rotation $model */
                if (isset($model->options) && is_string($model->options)) {
                    $model->options = Json::decode($model->options, true);
                }
            }
        });
    }
}
