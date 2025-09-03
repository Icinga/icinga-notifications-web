<?php

namespace Icinga\Module\Notifications\Api\V1;

use DateTime;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\Elements\Uuid;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Util\Json;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use ipl\Validator\EmailAddressValidator;
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
    type: 'object',
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
            properties: ['test' => 'value', 'test2' => 'value2']
        )
    ]
)]
#[OA\Schema(
    schema: 'ContactUUID',
    title: 'ContactUUID',
    description: 'An UUID representing a contact',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
    example: '9e868ad0-e774-465b-8075-c5a07e8f0726',
)]
class Contacts extends ApiV1
{
    /**
     * The route to handle a single contact
     *
     * @var string
     */
    public const ROUTE_WITH_IDENTIFIER = '/contacts/{identifier}';
    /**
     * The route to handle multiple contacts
     *
     * @var string
     */
    public const ROUTE_WITHOUT_IDENTIFIER = '/contacts';
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
        items: new OA\Items(
            ref: '#/components/schemas/ContactGroupUUID',
            description: 'Group UUIDs the contact belongs to',
        )
    )]
    protected ?array $groups = null;
    #[OA\Property(
        ref: '#/components/schemas/Addresses',
        description: 'Contact addresses by type',
    )]
    protected ?array $addresses = null;

    /**
     * Get a contact by UUID.
     *
     * @param Uuid $identifier
     * @return array
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: Contacts::ROUTE_WITH_IDENTIFIER,
        description: 'Get a contact by UUID',
        summary: 'Get a contact by UUID',
        tags: ['Contacts'],
    )]
    #[OA\Parameter(
        name: 'identifier',
        description: 'The UUID of the contact to retrieve',
        in: 'path',
        required: true,
        schema: new OA\Schema(ref: '#/components/schemas/ContactUUID')
    )]
    #[OA\Response(
        response: 200,
        description: 'Contact found',
        content: new OA\JsonContent(
            ref: '#/components/schemas/Contact'
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Contact not found',
        content: new OA\JsonContent(
            examples: [
                'IdentifierNotFound' => new OA\Examples(
                    example: 'IdentifierNotFound',
                    ref: '#/components/examples/IdentifierNotFound'
                ),
            ],
            schema: '#/components/schemas/ErrorResponse',
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Unprocessable Entity',
        content: new OA\JsonContent(
            examples: [
                'InvalidIdentifier' => new OA\Examples(
                    example: 'InvalidIdentifier',
                    ref: '#/components/examples/InvalidIdentifier'
                ),
            ],
            schema: '#/components/schemas/ErrorResponse',
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
     * @return array
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: Contacts::ROUTE_WITHOUT_IDENTIFIER,
        summary: 'List contacts or get specific contacts by UUID or filter parameters',
        tags: ['Contacts'],
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Filter Contacts by UUID',
        in: 'query',
        required: false,
        schema: new OA\Schema(ref: '#/components/schemas/UUID')
    )]
    #[OA\Parameter(
        name: 'full_name',
        description: 'Filter Contacts by full name',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'username',
        description: 'Filter Contacts by username',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string', maxLength: 254)
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful response',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                ref: '#/components/schemas/Contact',
                title: 'ContactResponse',
            )
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            examples: [
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Unprocessable Entity',
        content: new OA\JsonContent(
            examples: [
                new OA\Examples(
                    example: 'InvalidFilterParameter',
                    summary: 'Invalid filter parameter',
                    value: [
                        'status'  => 'error',
                        'message' => 'Invalid filter column x given, only id, full_name and username are allowed',
                    ],
                ),
                'IDParameterInvalidUUID' => new OA\Examples(
                    example: 'IDParameterInvalidUUID',
                    ref: '#/components/examples/IDParameterInvalidUUID'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
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
     * Update a contact by UUID.
     *
     * @param Uuid $identifier
     * @param requestBody $requestBody
     * @return array
     * @throws HttpBadRequestException
     * @throws HttpException
     */
    #[OA\Put(
        path: Contacts::ROUTE_WITH_IDENTIFIER,
        description: 'Update a contact by UUID',
        summary: 'Update a contact by UUID',
        tags: ['Contacts'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: '#/components/schemas/Contact'
        )
    )]
    #[OA\Parameter(
        name: 'identifier',
        description: 'The UUID of the contact to update',
        in: 'path',
        required: true,
        schema: new OA\Schema(ref: '#/components/schemas/ContactUUID')
    )]
    #[OA\Response(
        response: 201,
        description: 'Contact created',
        content: new OA\JsonContent(
            examples: [
                'ContactCreated' => new OA\Examples(
                    example: 'ContactCreated',
                    ref: '#/components/examples/ContactCreated'
                ),
            ],
            ref: '#/components/schemas/SuccessResponse'
        )
    )]
    #[OA\Response(
        response: 204,
        description: 'Contact updated',
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            examples: [
                'InvalidRequestBody' => new OA\Examples(
                    example: 'InvalidRequestBody',
                    ref: '#/components/examples/InvalidRequestBody'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 415,
        description: 'Unsupported Media Type',
        content: new OA\JsonContent(
            examples: [
                'ContentTypeNotSupported' => new OA\Examples(
                    example: 'ContentTypeNotSupported',
                    ref: '#/components/examples/ContentTypeNotSupported'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Unprocessable Entity',
        content: new OA\JsonContent(
            examples: [
                'GroupNotFound' => new OA\Examples(
                    example: 'GroupNotFound',
                    summary: 'Group not found',
                    value: [
                        'status'  => 'error',
                        'message' => 'Group does not exist: X',
                    ]
                ),
                'MissingRequiredRequestBodyField' => new OA\Examples(
                    example: 'MissingRequiredRequestBodyField',
                    ref: '#/components/examples/MissingRequiredRequestBodyField'
                ),
                'InvalidRequestBodyField' => new OA\Examples(
                    example: 'InvalidRequestBodyField',
                    ref: '#/components/examples/InvalidRequestBodyField'
                ),
                'InvalidIdentifier' => new OA\Examples(
                    example: 'InvalidIdentifier',
                    ref: '#/components/examples/InvalidIdentifier'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    public function put(Uuid $identifier, array $requestBody): array
    {
        if (empty((string) $identifier)) {
            $this->httpBadRequest('Identifier is required');
        }

        $data = $this->getValidatedRequestBodyData($requestBody);

        if ((string) $identifier !== $data['id']) {
            $this->httpBadRequest('Identifier mismatch');
        }

        $this->getDB()->beginTransaction();

        if (($contactId = self::getContactId($identifier)) !== null) {
            if (! empty($data['username'])) {
                $this->assertUniqueUsername($data['username'], $contactId);
            }

            if (! $channelID = Channels::getChannelId($data['default_channel'])) {
                $this->httpUnprocessableEntity('Default channel mismatch');
            }

            $this->getDB()->update('contact', [
                'full_name' => $data['full_name'],
                'username' => $data['username'] ?? null,
                'default_channel_id' => $channelID,
                'changed_at' => (int)(new DateTime())->format("Uv"),
            ], ['id = ?' => $contactId]);

            $markAsDeleted = ['deleted' => 'y'];
            $this->getDB()->update(
                'contact_address',
                $markAsDeleted,
                ['contact_id = ?' => $contactId, 'deleted = ?' => 'n']
            );
            $this->getDB()->update(
                'contactgroup_member',
                $markAsDeleted,
                ['contact_id = ?' => $contactId, 'deleted = ?' => 'n']
            );

            if (! empty($data['addresses'])) {
                $this->addAddresses($contactId, $data['addresses']);
            }

            if (! empty($data['groups'])) {
                $this->addGroups($contactId, $data['groups']);
            }

            $responseCode = 204;
        } else {
            $this->addContact($data);
            $responseCode = 201;
            $responseBody = '{"status": "success","message": "Contact created successfully"}';
        }

        $this->getDB()->commitTransaction();

        return $this->createArrayOfResponseData(statusCode: $responseCode, body: $responseBody ?? null);
    }

    /**
     * Create a new contact.
     *
     * @param requestBody $requestBody
     * @return array
     * @throws HttpException
     * @throws HttpBadRequestException
     */
    #[OA\Post(
        path: Contacts::ROUTE_WITHOUT_IDENTIFIER,
        description: 'Create a new contact',
        summary: 'Create a new contact',
        tags: ['Contacts'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: '#/components/schemas/Contact'
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Contact created',
        content: new OA\JsonContent(
            examples: [
                'ContactCreated' => new OA\Examples(
                    example: 'ContactCreated',
                    ref: '#/components/examples/ContactCreated'
                ),
            ],
            ref: '#/components/schemas/SuccessResponse'
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            examples: [
                'InvalidRequestBody' => new OA\Examples(
                    example: 'InvalidRequestBody',
                    ref: '#/components/examples/InvalidRequestBody'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 415,
        description: 'Unsupported Media Type',
        content: new OA\JsonContent(
            examples: [
                'ContentTypeNotSupported' => new OA\Examples(
                    example: 'ContentTypeNotSupported',
                    ref: '#/components/examples/ContentTypeNotSupported'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Unprocessable Entity',
        content: new OA\JsonContent(
            examples: [
                'ContactAlreadyExists' => new OA\Examples(
                    example: 'ContactAlreadyExists',
                    summary: 'Contact already exists',
                    value: [
                        'status'  => 'error',
                        'message' => 'Contact already exists',
                    ]
                ),
                'GroupNotFound' => new OA\Examples(
                    example: 'GroupNotFound',
                    summary: 'Group not found',
                    value: [
                        'status'  => 'error',
                        'message' => 'Group does not exist: X',
                    ]
                ),
                'MissingRequiredRequestBodyField' => new OA\Examples(
                    example: 'MissingRequiredRequestBodyField',
                    ref: '#/components/examples/MissingRequiredRequestBodyField'
                ),
                'InvalidRequestBodyField' => new OA\Examples(
                    example: 'InvalidRequestBodyField',
                    ref: '#/components/examples/InvalidRequestBodyField'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    public function post(array $requestBody): array
    {
        $data = $this->getValidatedRequestBodyData($requestBody);

        $this->getDB()->beginTransaction();

        // TODO: re-enable update via POST?
//        if (empty((string)$identifier)) {
            if ($this->getContactId($data['id']) !== null) {
                throw new HttpException(422, 'Contact already exists');
            }
//        } else {
//            $contactId = $this->getContactId($identifier);
//            if ($contactId === null) {
//                $this->httpNotFound('Contact not found');
//            }
//
//            if ($identifier === $data['id'] || $this->getContactId($data['id']) !== null) {
//                throw new HttpException(422, 'Contact already exists');
//            }
//
//            $this->removeContact($contactId);
//        }
        $this->addContact($data);

        $this->getDB()->commitTransaction();

//        $this->getResponse()->setHeader('Location', self::ENDPOINT . '/' . $data['id']);

        return $this->createArrayOfResponseData(
            statusCode: 201,
            body: '{"status": "success","message": "Contact created successfully"}'
        );
    }

    /**
     * Remove the contact with the given id
     *
     * @param Uuid $identifier
     * @return array
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     */
    #[OA\Delete(
        path: Contacts::ROUTE_WITH_IDENTIFIER,
        description: 'Delete a contact by UUID',
        summary: 'Delete a contact by UUID',
        tags: ['Contacts'],
    )]
    #[OA\Parameter(
        name: 'identifier',
        description: 'The UUID of the contact to delete',
        in: 'path',
        required: true,
        schema: new OA\Schema(ref: '#/components/schemas/ContactUUID')
    )]
    #[OA\Response(
        response: 204,
        description: 'Contact deleted',
    )]
    #[OA\Response(
        response: 404,
        description: 'Contact not found',
        content: new OA\JsonContent(
            examples: [
                'IdentifierNotFound' => new OA\Examples(
                    example: 'IdentifierNotFound',
                    ref: '#/components/examples/IdentifierNotFound'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Unprocessable Entity',
        content: new OA\JsonContent(
            examples: [
                'InvalidIdentifier' => new OA\Examples(
                    example: 'InvalidIdentifier',
                    ref: '#/components/examples/InvalidIdentifier'
                ),
            ],
            ref: '#/components/schemas/ErrorResponse'
        )
    )]
    public function delete(Uuid $identifier): array
    {
        if (empty((string) $identifier)) {
            $this->httpBadRequest('Identifier is required');
        }

        if (($contactId = self::getContactId($identifier)) === null) {
            $this->httpNotFound('Contact not found');
        }

        $this->removeContact($contactId);

        return $this->createArrayOfResponseData(statusCode: 204);
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

    /**
     * Get the contact id with the given identifier
     *
     * @param string $identifier
     *
     * @return ?int Returns null, if contact does not exist
     */
    public static function getContactId(string $identifier): ?int
    {
        /** @var stdClass|false $contact */
        $contact =  Database::get()->fetchOne(
            (new Select())
                ->from('contact')
                ->columns('id')
                ->where(['external_uuid = ?' => $identifier])
        );

        return $contact->id ?? null;
    }

    /**
     * Add the groups to the given contact
     *
     * @param int $contactId
     * @param string[] $groups
     *
     * @return void
     * @throws HttpException
     */
    private function addGroups(int $contactId, array $groups): void
    {
        foreach ($groups as $groupIdentifier) {
            $groupId = ContactGroups::getGroupId($groupIdentifier);
            if (! $groupId) {
                $this->httpUnprocessableEntity(
                    sprintf('Group with identifier %s does not exist', $groupIdentifier)
                );
            }

            Database::get()->insert('contactgroup_member', [
                'contact_id'        => $contactId,
                'contactgroup_id'   => $groupId,
                'changed_at'        => (int) (new DateTime())->format("Uv"),
            ]);
        }
    }

    /**
     * Add the addresses to the given contact
     *
     * @param int $contactId
     * @param array<string, string> $addresses
     *
     * @return void
     */
    private function addAddresses(int $contactId, array $addresses): void
    {
        foreach ($addresses as $type => $address) {
            Database::get()->insert('contact_address', [
                'contact_id'    => $contactId,
                'type'          => $type,
                'address'       => $address,
                'changed_at'            => (int) (new DateTime())->format("Uv"),
            ]);
        }
    }

    /**
     * Add a new contact with the given data
     *
     * @param requestBody $data
     *
     * @return void
     * @throws HttpException
     */
    private function addContact(array $data): void
    {
        if (! empty($data['username'])) {
            $this->assertUniqueUsername($data['username']);
        }
        if (! $channelID = Channels::getChannelId($data['default_channel'])) {
            $this->httpUnprocessableEntity('Default channel mismatch');
        }

        Database::get()->insert('contact', [
            'full_name'             => $data['full_name'],
            'username'              => $data['username'] ?? null,
            'default_channel_id'    => $channelID,
            'external_uuid'         => $data['id'],
            'changed_at'            => (int) (new DateTime())->format("Uv"),
        ]);

        $contactId = Database::get()->lastInsertId();

        if (! empty($data['addresses'])) {
            $this->addAddresses($contactId, $data['addresses']);
        }

        if (! empty($data['groups'])) {
            $this->addGroups($contactId, $data['groups']);
        }
    }

    /**
     * Remove the contact with the given id
     *
     * @param int $id
     *
     * @return void
     */
    private function removeContact(int $id): void
    {
        //TODO: "remove rotations|escalations with no members" taken from form. Is it properly?
        $this->getDB()->beginTransaction();

        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];
        $updateCondition = ['contact_id = ?' => $id, 'deleted = ?' => 'n'];

        $rotationAndMemberIds = $this->getDB()->fetchPairs(
            RotationMember::on($this->getDB())
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        $rotationMemberIds = array_keys($rotationAndMemberIds);
        $rotationIds = array_values($rotationAndMemberIds);

        $this->getDB()->update('rotation_member', $markAsDeleted + ['position' => null], $updateCondition);

        if (! empty($rotationMemberIds)) {
            $this->getDB()->update(
                'timeperiod_entry',
                $markAsDeleted,
                ['rotation_member_id IN (?)' => $rotationMemberIds, 'deleted = ?' => 'n']
            );
        }

        if (! empty($rotationIds)) {
            $rotationIdsWithOtherMembers = $this->getDB()->fetchCol(
                RotationMember::on($this->getDB())
                    ->columns('rotation_id')
                    ->filter(
                        Filter::all(
                            Filter::equal('rotation_id', $rotationIds),
                            Filter::unequal('contact_id', $id)
                        )
                    )->assembleSelect()
            );

            $toRemoveRotations = array_diff($rotationIds, $rotationIdsWithOtherMembers);

            if (! empty($toRemoveRotations)) {
                $rotations = Rotation::on($this->getDB())
                    ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
                    ->filter(Filter::equal('id', $toRemoveRotations));

                /** @var Rotation $rotation */
                foreach ($rotations as $rotation) {
                    $rotation->delete();
                }
            }
        }

        $escalationIds = $this->getDB()->fetchCol(
            RuleEscalationRecipient::on($this->getDB())
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        $this->getDB()->update('rule_escalation_recipient', $markAsDeleted, $updateCondition);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->getDB()->fetchCol(
                RuleEscalationRecipient::on($this->getDB())
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('contact_id', $id)
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $this->getDB()->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $this->getDB()->update('contactgroup_member', $markAsDeleted, $updateCondition);
        $this->getDB()->update('contact_address', $markAsDeleted, $updateCondition);

        $this->getDB()->update('contact', $markAsDeleted + ['username' => null], ['id = ?' => $id]);

        $this->getDB()->commitTransaction();
    }

    /**
     * Assert that the username is unique
     *
     * @param string $username
     * @param ?int $contactId The id of the contact to exclude
     *
     * @return void
     *
     * @throws HttpException if the username already exists
     */
    private function assertUniqueUsername(string $username, int $contactId = null): void
    {
        $stmt = (new Select())
            ->from('contact')
            ->columns('1')
            ->where(['username = ?' => $username]);

        if ($contactId) {
            $stmt->where(['id != ?' => $contactId]);
        }

        $user = Database::get()->fetchOne($stmt);

        if ($user) {
            $this->httpConflict('Username already exists');
        }
    }

    // TODO: validate via class attributes or openapi schema? Is it performant enough?
    /**
     * Get the validated POST|PUT request data
     *
     * @return requestBody
     *
     * @throws HttpBadRequestException if the request body is invalid
     */
    private function getValidatedRequestBodyData(array $data): array
    {
        $msgPrefix = 'Invalid request body: ';

        if (
            ! isset($data['id'], $data['full_name'], $data['default_channel'])
            || ! is_string($data['id'])
            || ! is_string($data['full_name'])
            || ! is_string($data['default_channel'])
        ) {
            $this->httpBadRequest(
                $msgPrefix . 'the fields id, full_name and default_channel must be present and of type string'
            );
        }

        if (! Uuid::isValid($data['id'])) {
            $this->httpBadRequest($msgPrefix . 'given id is not a valid UUID');
        }

        if (! Uuid::isValid($data['default_channel'])) {
            $this->httpBadRequest($msgPrefix . 'given default_channel is not a valid UUID');
        }

        if (! empty($data['username']) && ! is_string($data['username'])) {
            $this->httpBadRequest($msgPrefix . 'expects username to be a string');
        }

        if (! empty($data['groups'])) {
            if (! is_array($data['groups'])) {
                $this->httpBadRequest($msgPrefix . 'expects groups to be an array');
            }

            foreach ($data['groups'] as $group) {
                if (! is_string($group) || ! Uuid::isValid($group)) {
                    $this->httpBadRequest($msgPrefix . 'group identifiers must be valid UUIDs');
                }
            }
        }

        if (! empty($data['addresses'])) {
            if (! is_array($data['addresses'])) {
                $this->httpBadRequest($msgPrefix . 'expects addresses to be an array');
            }

            $addressTypes = array_keys($data['addresses']);

            $types = Database::get()->fetchCol(
                (new Select())
                    ->from('available_channel_type')
                    ->columns('type')
                    ->where(['type IN (?)' => $addressTypes])
            );

            if (count($types) !== count($addressTypes)) {
                $this->httpBadRequest(
                    sprintf(
                        $msgPrefix . 'undefined address type %s given',
                        implode(', ', array_diff($addressTypes, $types))
                    )
                );
            }
            //TODO: is it a good idea to check valid channel types here?, if yes,
            //default_channel and group identifiers must be checked here as well..404 OR 400?

            if (
                ! empty($data['addresses']['email'])
                && ! (new EmailAddressValidator())->isValid($data['addresses']['email'])
            ) {
                $this->httpBadRequest($msgPrefix . 'an invalid email address given');
            }
        }

        /** @var requestBody $data */
        return $data;
    }
}
