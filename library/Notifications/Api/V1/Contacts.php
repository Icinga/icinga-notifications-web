<?php

namespace Icinga\Module\Notifications\Api\V1;

use DateTime;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
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

    public function getEndpoint(): string
    {
        return 'contacts';
    }

    /**
     * Get a contact by UUID.
     *
     * @param string|null $identifier
     * @param string $queryFilter
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: '/contacts/{identifier}',
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
    public function get(?string $identifier, string $queryFilter): ResponseInterface
    {
        $stmt = (new Select())
            ->distinct()
            ->from('contact co')
            ->columns([
                'contact_id'      => 'co.id',
                'id'              => 'co.external_uuid',
                'full_name',
                'username',
                'default_channel' => 'ch.external_uuid',
            ])
            ->joinLeft('contact_address ca', 'ca.contact_id = co.id')
            ->joinLeft('channel ch', 'ch.id = co.default_channel_id')
            ->where(['co.deleted = ?' => 'n']);
        if ($identifier === null) {
            return $this->getPlural($queryFilter, $stmt);
        }

        $stmt->where(['co.external_uuid = ?' => $identifier]);

        /** @var stdClass|false $result */
        $result = Database::get()->fetchOne($stmt);

        if ($result === false) {
            throw new HttpNotFoundException('Contact not found');
        }

        $this->prepareRow($result);

        return $this->createResponse(body: Json::sanitize(['data' => [$result]]));
    }

    /**
     * List contacts or get specific contacts by filter parameters.
     *
     * @param string $queryFilter
     * @param Select $stmt
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
    #[OA\Get(
        path: '/contacts',
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
    private function getPlural(string $queryFilter, Select $stmt): ResponseInterface
    {
        $filter = $this->assembleFilter(
            $queryFilter,
            ['id', 'full_name', 'username'],
            'co.external_uuid'
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        return $this->createResponse(body: $this->createContentGenerator(Database::get(), $stmt));
    }

    /**
     * Update a contact by UUID.
     *
     * @param string $identifier
     * @param requestBody $requestBody
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpException
     * @throws JsonEncodeException
     */
    #[OA\Put(
        path: '/contacts/{identifier}',
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
    public function put(string $identifier, array $requestBody): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        $this->assertValidRequestBody($requestBody);

        if ($identifier !== $requestBody['id']) {
            throw new HttpException(422, 'Identifier mismatch');
        }

        $db = Database::get();
        $db->beginTransaction();

        if (($contactId = self::getContactId($identifier)) !== null) {
            if (! empty($requestBody['username'])) {
                $this->assertUniqueUsername($requestBody['username'], $contactId);
            }

            if (! $channelID = Channels::getChannelId($requestBody['default_channel'])) {
                throw new HttpException(422, 'Default channel mismatch');
            }

            $db->update('contact', [
                'full_name'          => $requestBody['full_name'],
                'username'           => $requestBody['username'] ?? null,
                'default_channel_id' => $channelID,
                'changed_at'         => (int) (new DateTime())->format("Uv"),
            ], ['id = ?' => $contactId]);

            $markAsDeleted = ['deleted' => 'y'];
            $db->update(
                'contact_address',
                $markAsDeleted,
                ['contact_id = ?' => $contactId, 'deleted = ?' => 'n']
            );
            $db->update(
                'contactgroup_member',
                $markAsDeleted,
                ['contact_id = ?' => $contactId, 'deleted = ?' => 'n']
            );

            if (! empty($requestBody['addresses'])) {
                $this->addAddresses($contactId, $requestBody['addresses']);
            }

            if (! empty($requestBody['groups'])) {
                $this->addGroups($contactId, $requestBody['groups']);
            }

            $result = $this->createResponse(204);
        } else {
            $this->addContact($requestBody);
            $result = $this->createResponse(
                201,
                [
                    'Location' => sprintf(
                        'notifications/api/%s/%s/%s',
                        self::VERSION,
                        $this->getEndpoint(),
                        $requestBody['id']
                    )
                ],
                Json::sanitize(['message' => 'Contact created successfully'])
            );
        }

        $db->commitTransaction();

        return $result;
    }

    /**
     * Create a new contact.
     *
     * @param string|null $identifier
     * @param requestBody $requestBody
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpException
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    #[OA\Post(
        path: '/contacts',
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
    public function post(?string $identifier, array $requestBody): ResponseInterface
    {
        $this->assertValidRequestBody($requestBody);

        $db = Database::get();
        $db->beginTransaction();

        $emptyIdentifier = empty($identifier);
        if (! $emptyIdentifier) {
            if ($identifier === $requestBody['id']) {
                throw new HttpException(
                    422,
                    'Identifier mismatch: the Payload id must be different from the URL identifier'
                );
            }
            $contactId = $this->getContactId($identifier);
            if ($contactId === null) {
                throw new HttpNotFoundException('Contact not found');
            }
        }

        if ($this->getContactId($requestBody['id']) !== null) {
            throw new HttpException(422, 'Contact already exists');
        }

        if (! $emptyIdentifier) {
            $this->removeContact($contactId);
        }
        $this->addContact($requestBody);

        $db->commitTransaction();


        return $this->createResponse(
            201,
            [
                'Location' => sprintf(
                    'notifications/api/%s/%s/%s',
                    self::VERSION,
                    $this->getEndpoint(),
                    $requestBody['id']
                )
            ],
            Json::sanitize(['message' => 'Contact created successfully'])
        );
    }

    /**
     * Remove the contact with the given id
     *
     * @param string $identifier
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     */
    #[OA\Delete(
        path: '/contacts/{identifier}',
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
    public function delete(string $identifier): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        if (($contactId = self::getContactId($identifier)) === null) {
            throw new HttpNotFoundException('Contact not found');
        }

        $db = Database::get();
        $db->beginTransaction();
        $this->removeContact($contactId);
        $db->commitTransaction();

        return $this->createResponse(204);
    }

    public function prepareRow(stdClass $row): void
    {
            $row->groups = ContactGroups::fetchGroupIdentifiers($row->contact_id);
            $row->addresses = self::fetchContactAddresses($row->contact_id);

            unset($row->contact_id);
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
            if ($groupId === null) {
                throw new HttpException(
                    422,
                    sprintf('Contactgroup with identifier %s does not exist', $groupIdentifier)
                );
            }

            Database::get()->insert('contactgroup_member', [
                'contact_id'      => $contactId,
                'contactgroup_id' => $groupId,
                'changed_at'      => (int) (new DateTime())->format("Uv"),
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
                'contact_id' => $contactId,
                'type'       => $type,
                'address'    => $address,
                'changed_at' => (int) (new DateTime())->format("Uv"),
            ]);
        }
    }

    /**
     * Add a new contact with the given data
     *
     * @param requestBody $requestBody
     *
     * @return void
     * @throws HttpException
     */
    private function addContact(array $requestBody): void
    {
        if (! empty($requestBody['username'])) {
            $this->assertUniqueUsername($requestBody['username']);
        }
        if (! $channelID = Channels::getChannelId($requestBody['default_channel'])) {
            throw new HttpException(422, 'Default channel mismatch');
        }

        Database::get()->insert('contact', [
            'full_name'          => $requestBody['full_name'],
            'username'           => $requestBody['username'] ?? null,
            'default_channel_id' => $channelID,
            'external_uuid'      => $requestBody['id'],
            'changed_at'         => (int) (new DateTime())->format("Uv"),
        ]);

        $contactId = Database::get()->lastInsertId();

        if (! empty($requestBody['addresses'])) {
            $this->addAddresses($contactId, $requestBody['addresses']);
        }

        if (! empty($requestBody['groups'])) {
            $this->addGroups($contactId, $requestBody['groups']);
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

        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];
        $updateCondition = ['contact_id = ?' => $id, 'deleted = ?' => 'n'];
        $db = Database::get();

        $rotationAndMemberIds = $db->fetchPairs(
            RotationMember::on($db)
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        $rotationMemberIds = array_keys($rotationAndMemberIds);
        $rotationIds = array_values($rotationAndMemberIds);

        $db->update('rotation_member', $markAsDeleted + ['position' => null], $updateCondition);

        if (! empty($rotationMemberIds)) {
            $db->update(
                'timeperiod_entry',
                $markAsDeleted,
                ['rotation_member_id IN (?)' => $rotationMemberIds, 'deleted = ?' => 'n']
            );
        }

        if (! empty($rotationIds)) {
            $rotationIdsWithOtherMembers = $db->fetchCol(
                RotationMember::on($db)
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
                $rotations = Rotation::on($db)
                    ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
                    ->filter(Filter::equal('id', $toRemoveRotations));

                /** @var Rotation $rotation */
                foreach ($rotations as $rotation) {
                    $rotation->delete();
                }
            }
        }

        $escalationIds = $db->fetchCol(
            RuleEscalationRecipient::on($db)
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        $db->update('rule_escalation_recipient', $markAsDeleted, $updateCondition);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $db->fetchCol(
                RuleEscalationRecipient::on($db)
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('contact_id', $id)
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $db->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $db->update('contactgroup_member', $markAsDeleted, $updateCondition);
        $db->update('contact_address', $markAsDeleted, $updateCondition);

        $db->update('contact', $markAsDeleted + ['username' => null], ['id = ?' => $id]);
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
            throw new HttpException(422, sprintf('Username %s already exists', $username));
        }
    }

    /**
     * Validate the request body for required fields and types
     *
     * @param array $requestBody
     *
     * @return void
     *
     * @throws HttpBadRequestException
     * @throws HttpException
     */
    private function assertValidRequestBody(array $requestBody): void
    {
        $msgPrefix = 'Invalid request body: ';

        if (
            ! isset($requestBody['id'], $requestBody['full_name'], $requestBody['default_channel'])
            || ! is_string($requestBody['id'])
            || ! is_string($requestBody['full_name'])
            || ! is_string($requestBody['default_channel'])
        ) {
            throw new HttpException(
                422,
                $msgPrefix . 'the fields id, full_name and default_channel must be present and of type string'
            );
        }

        if (! Uuid::isValid($requestBody['id'])) {
            throw new HttpBadRequestException($msgPrefix . 'given id is not a valid UUID');
        }

        if (! Uuid::isValid($requestBody['default_channel'])) {
            throw new HttpBadRequestException($msgPrefix . 'given default_channel is not a valid UUID');
        }

        if (! empty($requestBody['username']) && ! is_string($requestBody['username'])) {
            throw new HttpBadRequestException($msgPrefix . 'expects username to be a string');
        }

        if (! empty($requestBody['groups'])) {
            if (! is_array($requestBody['groups'])) {
                throw new HttpBadRequestException($msgPrefix . 'expects groups to be an array');
            }

            foreach ($requestBody['groups'] as $group) {
                if (! is_string($group) || ! Uuid::isValid($group)) {
                    throw new HttpException(
                        422,
                        sprintf($msgPrefix . 'the group identifier %s is not a valid UUID', $group)
                    );
                }
            }
        }

        if (! empty($requestBody['addresses'])) {
            if (! is_array($requestBody['addresses'])) {
                throw new HttpBadRequestException($msgPrefix . 'expects addresses to be an array');
            }

            $addressTypes = array_keys($requestBody['addresses']);

            $types = Database::get()->fetchCol(
                (new Select())
                    ->from('available_channel_type')
                    ->columns('type')
                    ->where(['type IN (?)' => $addressTypes])
            );

            if (count($types) !== count($addressTypes)) {
                throw new HttpException(
                    422,
                    sprintf(
                        $msgPrefix . 'undefined address type %s given',
                        implode(', ', array_diff($addressTypes, $types))
                    )
                );
            }
            //TODO: is it a good idea to check valid channel types here?, if yes,
            //default_channel and group identifiers must be checked here as well..404 OR 400?

            if (
                ! empty($requestBody['addresses']['email'])
                && ! (new EmailAddressValidator())->isValid($requestBody['addresses']['email'])
            ) {
                throw new HttpBadRequestException($msgPrefix . 'an invalid email address given');
            }
        }
    }

    /**
     * Fetch the user(contact) identifiers of the contactgroup with the given id from the contactgroup_member table
     *
     * @param int $contactgroupId
     *
     * @return string[]
     */
    public static function fetchUserIdentifiers(int $contactgroupId): array
    {
        return Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('co.external_uuid')
                ->joinLeft('contact co', 'co.id = cgm.contact_id')
                ->where(['cgm.contactgroup_id = ?' => $contactgroupId])
                ->groupBy('co.external_uuid')
        );
    }
}
