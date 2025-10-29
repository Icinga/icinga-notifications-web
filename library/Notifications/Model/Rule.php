<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $id
 * @property string $name
 * @property int $source_id
 * @property ?int $timeperiod_id
 * @property ?string $object_filter
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Source $source
 * @property Query|RuleEscalation $rule_escalation
 * @property Query|Incident $incident
 * @property Query|IncidentHistory $incident_history
 */
class Rule extends Model
{
    public function getTableName(): string
    {
        return 'rule';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'source_id',
            'timeperiod_id',
            'object_filter',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'name'          => t('Name'),
            'source_id'     => t('Source ID'),
            'timeperiod_id' => t('Timeperiod ID'),
            'object_filter' => t('Object Filter'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): array
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('source', Source::class);
        $relations->hasMany('rule_escalation', RuleEscalation::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_history', IncidentHistory::class)->setJoinType('LEFT');
    }
}
