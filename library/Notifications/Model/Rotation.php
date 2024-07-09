<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Util\Json;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Contract\RetrieveBehavior;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;

/**
 * Rotation
 *
 * @property int $id
 * @property int $schedule_id
 * @property ?int $priority
 * @property string $name
 * @property string $mode
 * @property string|array $options
 * @property string $first_handoff
 * @property DateTime $actual_handoff
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Schedule $schedule
 * @property Query|RotationMember $member
 * @property Query|Timeperiod $timeperiod
 */
class Rotation extends Model
{
    public function getTableName(): string
    {
        return 'rotation';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'schedule_id',
            'priority',
            'name',
            'mode',
            'options',
            'first_handoff',
            'actual_handoff',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'schedule_id'       => t('Schedule'),
            'priority'          => t('Priority'),
            'name'              => t('Name'),
            'mode'              => t('Mode'),
            'first_handoff'     => t('First Handoff'),
            'actual_handoff'    => t('Actual Handoff'),
            'changed_at'        => t('Changed At')
        ];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('schedule', Schedule::class);

        $relations->hasMany('member', RotationMember::class);

        $relations->hasOne('timeperiod', Timeperiod::class)
            ->setForeignKey('owned_by_rotation_id');
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp([
            'actual_handoff',
            'changed_at'
        ]));
        $behaviors->add(new BoolCast(['deleted']));
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

    /**
     * Delete rotation and all related references
     *
     * The call must be wrapped in a transaction
     *
     * @return void
     */
    public function delete(): void
    {
        $db = Database::get();
        $transactionStarted = false;
        if (! $db->inTransaction()) {
            $transactionStarted = true;
            $db->beginTransaction();
        }

        if ($this->timeperiod instanceof Timeperiod) {
            $timeperiodId = $this->timeperiod->id;
        } else {
            $timeperiodId = $this->timeperiod->columns('id')->first();
        }

        $changedAt = time() * 1000;
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];

        $db->update('timeperiod_entry', $markAsDeleted, ['timeperiod_id = ?' => $timeperiodId]);
        $db->update('timeperiod', $markAsDeleted, ['id = ?' => $timeperiodId]);
        $db->update('rotation_member', $markAsDeleted + ['position' => null], ['rotation_id = ?' => $this->id]);

        $db->update(
            'rotation',
            $markAsDeleted + ['priority' => null, 'first_handoff' => null],
            ['id = ?' => $this->id]
        );

        $requirePriorityUpdate = true;
        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
            $rotations = self::on($db)
                ->columns('1')
                ->filter(Filter::equal('schedule_id', $this->schedule_id))
                ->filter(Filter::equal('priority', $this->priority))
                ->first();

            $requirePriorityUpdate = $rotations === null;
        }

        if ($requirePriorityUpdate) {
            $affectedRotations = self::on($db)
                ->columns('id')
                ->filter(Filter::equal('schedule_id', $this->schedule_id))
                ->filter(Filter::greaterThan('priority', $this->priority))
                ->orderBy('priority', SORT_ASC);

            foreach ($affectedRotations as $rotation) {
                $db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $rotation->id]
                );
            }
        }

        if ($transactionStarted) {
            $db->commitTransaction();
        }
    }
}
