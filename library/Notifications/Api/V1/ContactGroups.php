<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Module\Notifications\Common\Database;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Contactgroup",
    properties: [
        new OA\Property(
            property: "id",
            ref: "#/components/schemas/ContactGroupUUID",
            description: "The UUID of the contactgroup"
        ),
        new OA\Property(
            property: "name",
            description: "The full name of the contactgroup",
            type: "string"
        ),
        new OA\Property(
            property: "users",
            description: "List of user identifiers (UUIDs) that belong to this contactgroup",
            type: "array",
            items: new OA\Items(ref: "#/components/schemas/ContactUUID")
        )
    ],
    type: "object"
)]
#[OA\Schema(
    schema: 'ContactGroupUUID',
    title: 'ContactGroupUUID',
    description: 'An UUID representing a contact group',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
    example: '3fa85f64-5717-4562-b3fc-2c963f66afa6',
)]
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
