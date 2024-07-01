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
 * Contactgroup Member
 *
 * @param string $contactgroup_id
 * @param string $contact_id
 * @param DateTime $changed_at
 * @param bool $deleted
 *
 * @property Query | Contactgroup $contactgroup
 * @property Query | Contact $contact
 */
class ContactgroupMember extends Model
{
    public function getTableName(): string
    {
        return 'contactgroup_member';
    }

    public function getKeyName(): array
    {
        return ['contactgroup_id', 'contact_id'];
    }

    public function getColumns(): array
    {
        return [
            'contactgroup_id',
            'contact_id',
            'changed_at',
            'deleted'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('contactgroup', Contactgroup::class);
        $relations->belongsTo('contact', Contact::class);
    }
}
