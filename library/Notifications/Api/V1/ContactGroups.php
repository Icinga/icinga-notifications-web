<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Module\Notifications\Common\Database;
use ipl\Sql\Select;

class ContactGroups extends ApiV1
{


    /**
     * Fetch the group identifiers of the contact with the given id
     *
     * @param int $contactId
     *
     * @return string[]
     */
    public static function fetchGroupIdentifiers(int $contactId): array
    {
        return Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('cg.external_uuid')
                ->joinLeft('contactgroup cg', 'cg.id = cgm.contactgroup_id')
                ->where(['cgm.contact_id = ?' => $contactId])
                ->groupBy('cg.external_uuid')
        );
    }
}
