<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Orm\Query;

/**
 * @property int $id
 * @property int $rule_id
 * @property int $position
 * @property ?string $condition
 * @property ?string $name
 * @property ?int $fallback_for
 *
 * @property Query|Contact $contact
 * @property Query|Incident $incident
 * @property Query|IncidentHistory $incident_history
 * @property Query|Rule $rule
 * @property Query|RuleEscalationRecipient $rule_escalation_recipient
 */
class RuleEscalation extends Model
{
    public function getTableName()
    {
        return 'rule_escalation';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'rule_id',
            'position',
            'condition',
            'name',
            'fallback_for'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'rule_id'       => t('Rule ID'),
            'position'      => t('Position'),
            'condition'     => t('Condition'),
            'name'          => t('Name'),
            'fallback_for'  => t('Fallback For')
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return ['position'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('rule', Rule::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_rule_escalation_state');

        $relations
            ->belongsToMany('contact', Contact::class)
            ->through('rule_escalation_recipient')
            ->setJoinType('LEFT');

        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class)
            ->setJoinType('LEFT');
    }
}
