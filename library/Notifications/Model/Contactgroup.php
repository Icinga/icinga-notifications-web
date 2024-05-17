<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

/**
 * Contact group
 *
 * @param int $id
 * @param string $name
 * @param string $color
 */
class Contactgroup extends Model
{
    public function getTableName()
    {
        return 'contactgroup';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name',
            'color'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class);
        $relations
            ->belongsToMany('contact', Contact::class)
            ->through('contactgroup_member')
            ->setJoinType('LEFT');
    }
}
