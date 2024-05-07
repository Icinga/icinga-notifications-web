<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $id
 * @property string $name
 *
 * @property Rotation|Query $rotation
 * @property RuleEscalationRecipient|Query $rule_escalation_recipient
 * @property IncidentHistory|Query $incident_history
 */
class Schedule extends Model
{
    public function getTableName(): string
    {
        return 'schedule';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return ['name' => t('Name')];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }

    public function getDefaultSort(): string
    {
        return 'name';
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('rotation', Rotation::class);
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class);
    }
}
