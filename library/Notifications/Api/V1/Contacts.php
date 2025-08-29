<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\Elements\Uuid;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Select;
use stdClass;
use OpenApi\Attributes as OA;

/**
 * @phpstan-type requestBody array{
 *       id: string,
 *       full_name: string,
 *       default_channel: string,
 *       username?: string,
 *       groups?: string[],
 *       addresses?: array<string,string>
 *   }
 */
#[OA\Schema(
    schema: 'Contact',
    description: 'Schema that represents a contact in the Icinga Notifications API',
    required: [
        'id',
        'full_name',
        'default_channel',
    ],
    type: "object",
)]
#[OA\Schema(
    schema: 'Addresses',
    properties: [
        new OA\Property(
            property: 'email',
            description: "User's email address",
            type: 'string',
            format: 'email',
            nullable: true
        ),
        new OA\Property(
            property: 'rocketchat',
            description: 'Rocket.Chat identifier or URL',
            type: 'string',
            nullable: true
        ),
        new OA\Property(
            property: 'webhook',
            description: 'Comma-separated list of webhook URLs or identifiers',
            type: 'string',
            nullable: true
        ),
    ],
    type: 'object',
    additionalProperties: false,
    attachables: [
        new OA\Attachable(
            properties: ["test" => "value", "test2" => "value2"]
        )
    ]
)]
#[OA\Schema(
    schema: "ContactUUID",
    description: 'An UUID representing a contact',
    allOf: [
        new OA\Schema(ref: "#/components/schemas/UUID"),
        new OA\Schema(example: '9e868ad0-e774-465b-8075-c5a07e8f0726'),
    ],
)]
class Contacts extends ApiV1
{
    #[OA\Property(
        ref: '#/components/schemas/ContactUUID',
    )]
    protected string $id;
    #[OA\Property(
        description: 'The full name of the contact',
        type: 'string',
        example: 'Icinga User',
    )]
    protected string $full_name;
    #[OA\Property(
        description: 'The username of the contact',
        type: 'string',
        maxLength: 254,
        example: 'icingauser'
    )]
    protected ?string $username = null;
    #[OA\Property(
        ref: '#/components/schemas/ChannelUUID',
        description: 'The default channel UUID for the contact'
    )]
    protected string $default_channel;
    #[OA\Property(
        description: 'List of group UUIDs the contact belongs to',
        type: 'array',
        items: new OA\Items(ref: "#/components/schemas/ContactGroupUUID")
    )]
    protected ?array $groups = null;
    #[OA\Property(
        ref: "#/components/schemas/Addresses",
        description: "Contact addresses by type",
    )]
    protected ?array $addresses = null;

    /**
     * Get a contact by UUID.
     *
     * @return void
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: '/contacts/{identifier}',
        description: 'Get a contact by UUID',
        summary: 'Get a contact by UUID',
        tags: ["Contacts"],
    )]
    #[OA\Parameter(
        name: 'identifier',
        description: 'The UUID of the contact to retrieve',
        in: 'path',
        required: true,
        schema: new OA\Schema(ref: "#/components/schemas/ContactUUID")
    )]
    #[OA\Response(
        response: 200,
        description: 'Contact found',
        content: new OA\JsonContent(
            ref: "#/components/schemas/Contact"
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            examples: [
                new OA\Examples(
                    example: 'InvalidUUID',
                    summary: 'Invalid identifier',
                    value: ['status' => 'error', 'message' => 'The given identifier is not a valid UUID']
                ),
                new OA\Examples(
                    example: 'IllegalFilter',
                    summary: 'Filter parameter is not allowed',
                    value: ['status' => 'error', 'message' => 'Filter parameter is not allowed']
                )
            ],
            schema: "#/components/schemas/ErrorResponse",
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Contact not found',
        content: new OA\JsonContent(
            examples: [
                new OA\Examples(
                    example: 'ContactNotFound',
                    summary: 'Contact not found',
                    value: ['status' => 'error', 'message' => 'Contact not found']
                )
            ],
            schema: "#/components/schemas/ErrorResponse",
        )
    )]
    public function get(Uuid $identifier): array
    {
        $stmt = $this->createSelectStmt();

        $stmt->where(['co.external_uuid = ?' => $identifier]);

        /** @var stdClass|false $result */
        $result = $this->getDB()->fetchOne($stmt);

        if (empty($result)) {
            $this->httpNotFound('Contact not found');
        }

        $this->enrichRow($result, true);

        unset($result->contact_id);

        return $this->createArrayOfResponseData(body: Json::sanitize($result));
    }

    /**
     * List contacts or get specific contacts by UUID or filter parameters.
     *
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: "/contacts",
        summary: "List contacts or get specific contacts by UUID or filter parameters",
        tags: ["Contacts"],
    )]
    #[OA\Parameter(
        name: "id",
        description: "Filter Contacts by UUID",
        in: "query",
        required: false,
        schema: new OA\Schema(ref: "#/components/schemas/UUID")
    )]
    #[OA\Parameter(
        name: "full_name",
        description: "Filter Contacts by full name",
        in: "query",
        required: false,
        schema: new OA\Schema(type: "string")
    )]
    #[OA\Parameter(
        name: "username",
        description: "Filter Contacts by username",
        in: "query",
        required: false,
        schema: new OA\Schema(type: "string", maxLength: 254)
    )]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(
                ref: "#/components/schemas/Contact",
                title: "ContactResponse",
            )
        )
    )]
    #[OA\Response(
        response: 400,
        description: "Bad request",
        content: new OA\JsonContent(
            examples: [
                new OA\Examples(
                    example: "InvalidFilterParameter",
                    summary: "Invalid filter parameter",
                    value: [
                        "status" => "error",
                        "message" => "Invalid filter column id given, only id, full_name and username are allowed"
                    ]
                ),
            ],
            schema: "#/components/schemas/ErrorResponse",
        )
    )]
    public function getPlural(): array
    {
        $stmt = $this->createSelectStmt();

        $filter = $this->createFilterFromFilterStr(
            $this->createFilterRuleListener(
                ['id', 'full_name', 'username'],
                'co.external_uuid'
            )
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        return $this->createArrayOfResponseData(
            body: $this->createContentGenerator($this->getDB(), $stmt, $this->enrichRow())
        );
    }

    /**
     * Enrich the given row with groups and addresses
     *
     * @param ?stdClass $row
     * @param bool $exec
     * @return ?callable Returns a callable that enriches the row, if $exec is false
     */
    private function enrichRow(?stdClass $row = null, bool $exec = false): ?callable
    {
        $enrich = function (stdClass $row) {
            $row->groups = ContactGroups::fetchGroupIdentifiers($row->contact_id);
            $row->addresses = self::fetchContactAddresses($row->contact_id);
        };
        $return = null;
        $exec ? $enrich($row) ?? null : $return = $enrich;

        return $return;
    }

    /**
     * Fetch the addresses of the contact with the given id
     *
     * @param int $contactId
     *
     * @return array
     */
    public static function fetchContactAddresses(int $contactId): array
    {
        /** @var array<string, string> $addresses */
        $addresses = Database::get()->fetchPairs(
            (new Select())
                ->from('contact_address')
                ->columns(['type', 'address'])
                ->where(['contact_id = ?' => $contactId])
        );

        return $addresses;
    }

    /**
     * Create a base Select query for contacts
     *
     * @return Select
     */
    private function createSelectStmt(): Select
    {
        return (new Select())
            ->distinct()
            ->from('contact co')
            ->columns([
                'contact_id' => 'co.id',
                'id' => 'co.external_uuid',
                'full_name',
                'username',
                'default_channel' => 'ch.external_uuid',
            ])
            ->joinLeft('contact_address ca', 'ca.contact_id = co.id')
            ->joinLeft('channel ch', 'ch.id = co.default_channel_id')
            ->where(['co.deleted = ?' => 'n']);
    }
}
