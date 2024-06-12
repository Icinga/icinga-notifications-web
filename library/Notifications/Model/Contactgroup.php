<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Contact group
 *
 * @param int $id
 * @param string $name
 *
 * @property Query | Contact $contact
 * @property Query | RuleEscalationRecipient $rule_escalation_recipient
 * @property Query | IncidentHistory $incident_history
 */
class Contactgroup extends Model
{
    public function getTableName(): string
    {
        return 'contactgroup';
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

    public function createRelations(Relations $relations): void
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
