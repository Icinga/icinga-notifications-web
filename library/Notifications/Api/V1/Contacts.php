<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\EndpointInterface;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1Delete;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1Get;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1GetPlural;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1Post;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1Put;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Parameter\PathParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Parameter\QueryParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Example\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Schema\SchemaUUID;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\Data\Contact as ContactData;
use Icinga\Module\Notifications\Repository\ContactRepository;
use Icinga\Util\Json;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

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
        'default_channel'
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'Addresses',
    description: 'Schema that represents a contact\'s addresses',
    type: 'object',
    example: ['webhook' => '@nickname'],
    additionalProperties: new OA\AdditionalProperties(
        type: 'string'
    )
)]
#[SchemaUUID(
    entityName: 'Contact',
    example: '9e868ad0-e774-465b-8075-c5a07e8f0726',
)]
#[SchemaUUID(
    entityName: 'NewContact',
    example: '52668ad0-e774-465b-8075-c5a07e8f0726',
)]
class Contacts extends ApiV1 implements RequestHandlerInterface, EndpointInterface
{
    public const REQUIRED_FIELDS = [
        'id',
        'full_name',
        'default_channel'
    ];
    public const REQUIRED_FIELD_TYPES = [
        'id' => 'string',
        'full_name' => 'string',
        'default_channel' => 'string'
    ];

    #[OA\Examples(
        example: 'ContactgroupNotExists',
        summary: 'Contact Group does not exist',
        value: ['message' => 'Contact Group with identifier x does not exist']
    )]
    #[OA\Examples(
        example: 'InvalidAddressType',
        summary: 'Invalid address type',
        value: ['message' => 'Invalid request body: undefined address type x given']
    )]
    #[OA\Examples(
        example: 'InvalidAddressFormat',
        summary: 'Invalid address format',
        value: ['message' => 'Invalid request body: expects addresses to be an array']
    )]
    #[OA\Examples(
        example: 'InvalidContactgroupUUID',
        summary: 'Invalid Contact Group UUID',
        value: ['message' => 'Invalid request body: the group identifier invalid_uuid is not a valid UUID']
    )]
    #[OA\Examples(
        example: 'InvalidContactgroupUUIDFormat',
        summary: 'Invalid Contact Group UUID format',
        value: ['message' => 'Invalid request body: an invalid group identifier format given']
    )]
    #[OA\Examples(
        example: 'InvalidDefaultChannelUUID',
        summary: 'Invalid default_channel UUID',
        value: ['message' => 'Invalid request body: given default_channel is not a valid UUID']
    )]
    #[OA\Examples(
        example: 'InvalidGroupsFormat',
        summary: 'Invalid groups format',
        value: ['message' => 'Invalid request body: expects groups to be an array']
    )]
    #[OA\Examples(
        example: 'MissingAddress',
        summary: 'Missing address',
        value: ['message' => 'Invalid request body: Address according to default_channel type x is required']
    )]
    #[OA\Examples(
        example: 'UsernameAlreadyExists',
        summary: 'Username already exists',
        value: ['message' => 'Username x already exists']
    )]
    protected array $specificResponses = [];
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
        example: 'icingauser',
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
            ref: '#/components/schemas/ContactgroupUUID',
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
    #[OadV1Get(
        entityName: 'Contact',
        path: '/contacts/{identifier}',
        description: 'Retrieve detailed information about a specific notification Contact using its UUID',
        summary: 'Get a specific Contact by its UUID',
        tags: ['Contacts'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Contact to retrieve',
                identifierSchema: 'ContactUUID'
            ),
        ],
    )]
    public function get(?string $identifier, string $queryFilter): ResponseInterface
    {
        $stmt = (new Select())
            ->from('contact co')
            ->columns([
                'contact_id'      => 'co.id',
                'id'              => 'co.external_uuid',
                'full_name',
                'username',
                'default_channel' => 'ch.external_uuid',
            ])
            ->joinLeft('channel ch', 'ch.id = co.default_channel_id')
            ->where(['co.deleted = ?' => 'n']);

        if ($identifier === null) {
            return $this->getPlural($queryFilter, $stmt);
        }

        $stmt->where(['co.external_uuid = ?' => static::transformUUIDForDB(Database::get(), $identifier)]);

        /** @var stdClass|false $result */
        $result = Database::get()->fetchOne($stmt);

        if ($result === false) {
            throw new HttpNotFoundException('Contact not found');
        }

        $this->prepareRow($result);

        return $this->createResponse(body: Json::sanitize(['data' => $result]));
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
    #[OadV1GetPlural(
        entityName: 'Contact',
        path: '/contacts',
        description: 'Retrieve all Contacts or filter them by parameters.',
        summary: 'List all Contacts or filter by parameters',
        tags: ['Contacts'],
        parameters: [
            new QueryParameter(
                name: 'id',
                description: 'Filter Contacts by UUID',
                schema: new SchemaUUID(entityName: 'Contact'),
            ),
            new QueryParameter(
                name: 'full_name',
                description: 'Filter Contacts by full name',
            ),
            new QueryParameter(
                name: 'username',
                description: 'Filter Contacts by username',
                schema: new OA\Schema(type: 'string', maxLength: 254)
            ),
        ],
        responses: []
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

        return $this->createResponse(body: $this->createContentGenerator($stmt));
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
    #[OadV1Put(
        entityName: 'Contact',
        path: '/contacts/{identifier}',
        description: 'Update a Contact by UUID, if it doesn\'t exist, it will be created. \
        The identifier must be the same as the payload id',
        summary: 'Update a Contact by UUID',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/Contact'
            )
        ),
        tags: ['Contacts'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Contact to Update',
                identifierSchema: 'ContactUUID'
            )
        ],
        examples422: [
            new ResponseExample('ContactgroupNotExists'),
            new ResponseExample('InvalidAddressFormat'),
            new ResponseExample('InvalidAddressType'),
            new ResponseExample('InvalidContactgroupUUID'),
            new ResponseExample('InvalidContactgroupUUIDFormat'),
            new ResponseExample('InvalidDefaultChannelUUID'),
            new ResponseExample('InvalidGroupsFormat'),
            new ResponseExample('MissingAddress'),
            new ResponseExample('UsernameAlreadyExists'),
        ]
    )]
    public function put(string $identifier, array $requestBody): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        Database::get()->beginTransaction();

        $this->assertValidRequestBody($requestBody);

        if ($identifier !== $requestBody['id']) {
            throw new HttpException(422, 'Identifier mismatch');
        }

        if (($contactId = self::getContactId($identifier)) !== null) {
            $this->updateContact($requestBody, $contactId);

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
                    ),
                    'X-Resource-Identifier' => $requestBody['id']
                ],
                Json::sanitize(['message' => 'Contact created successfully'])
            );
        }

        Database::get()->commitTransaction();

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
    #[OadV1Post(
        entityName: 'Contact',
        path: '/contacts',
        description: 'Create a new Contact',
        summary: 'Create a new Contact',
        tags: ['Contacts'],
        examples422: [
            new ResponseExample('ContactgroupNotExists'),
            new ResponseExample('InvalidAddressType'),
            new ResponseExample('InvalidAddressFormat'),
            new ResponseExample('InvalidContactgroupUUID'),
            new ResponseExample('InvalidContactgroupUUIDFormat'),
            new ResponseExample('InvalidDefaultChannelUUID'),
            new ResponseExample('InvalidGroupsFormat'),
            new ResponseExample('MissingAddress'),
            new ResponseExample('UsernameAlreadyExists'),
        ]
    )]
    #[OadV1Post(
        entityName: 'Contact',
        path: '/contacts/{identifier}',
        description: 'Replace a Contact by UUID, the identifier must be different from the payload id',
        summary: 'Replace a Contact by UUID',
        tags: ['Contacts'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the contact to create',
                identifierSchema: 'ContactUUID'
            )
        ],
        examples422: [
            new ResponseExample('ContactgroupNotExists'),
            new ResponseExample('InvalidAddressType'),
            new ResponseExample('InvalidAddressFormat'),
            new ResponseExample('InvalidContactgroupUUID'),
            new ResponseExample('InvalidContactgroupUUIDFormat'),
            new ResponseExample('InvalidDefaultChannelUUID'),
            new ResponseExample('InvalidGroupsFormat'),
            new ResponseExample('MissingAddress'),
            new ResponseExample('UsernameAlreadyExists'),
        ]
    )]
    public function post(?string $identifier, array $requestBody): ResponseInterface
    {
        $this->assertValidRequestBody($requestBody);

        Database::get()->beginTransaction();

        $emptyIdentifier = $identifier === null;

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
            (new ContactRepository(Database::get()))->delete($contactId);
        }

        $this->addContact($requestBody);
        Database::get()->commitTransaction();

        return $this->createResponse(
            201,
            [
                'Location' => sprintf(
                    'notifications/api/%s/%s/%s',
                    self::VERSION,
                    $this->getEndpoint(),
                    $requestBody['id']
                ),
                'X-Resource-Identifier' => $requestBody['id']
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
    #[OadV1Delete(
        entityName: 'Contact',
        path: '/contacts/{identifier}',
        description: 'Delete a Contact by UUID',
        summary: 'Delete a Contact by UUID',
        tags: ['Contacts'],
    )]
    public function delete(string $identifier): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        $contactId = $this->getContactId($identifier);

        if ($contactId === null) {
            throw new HttpNotFoundException('Contact not found');
        }

        Database::get()->beginTransaction();
        (new ContactRepository(Database::get()))->delete($contactId);
        Database::get()->commitTransaction();

        return $this->createResponse(204);
    }

    public function prepareRow(stdClass $row): void
    {
        $row->id = static::getUUIDString($row->id);
        $row->default_channel = static::getUUIDString($row->default_channel);
        $row->groups = ContactGroups::fetchGroupIdentifiers($row->contact_id);
        $row->addresses = self::fetchContactAddresses($row->contact_id) ?: new stdClass();

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
                ->where([
                    'contact_id = ?' => $contactId,
                    'deleted = ?' => 'n'
                ])
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
        $contact = Database::get()->fetchOne(
            (new Select())
                ->from('contact')
                ->columns('id')
                ->where([
                    'external_uuid = ?' => static::transformUUIDForDB(Database::get(), $identifier),
                    'deleted = ?' => 'n'
                ])
        );

//        if ($contact === false) {
//            $deletedContact = Database::get()
//                ->fetchCol('SELECT id FROM contact WHERE external_uuid = ?', [$identifier]);
//
//            if (! empty($deletedContact)) {
//                throw new HttpException(422, 'Contact id is not available: ' . $identifier);
//            }
//        }

        return $contact->id ?? null;

//        $contact = Database::get()
//                ->fetchCol('SELECT id FROM contact WHERE external_uuid = ?', [$identifier]);
//
//        return $contact[0] ?? null;
    }

    /**
     * Add a new contact with the given data
     *
     * @param requestBody $requestBody
     *
     * @return void
     *
     * @throws HttpException
     */
    private function addContact(array $requestBody): void
    {
        if (! empty($requestBody['username'])) {
            $this->assertUniqueUsername($requestBody['username']);
        }

        (new ContactRepository(Database::get()))->create($this->createContactData($requestBody));
    }

    /**
     * Update the contact with the given id with the given data
     *
     * @param requestBody $requestBody
     * @param int $contactId
     *
     * @return void
     *
     * @throws HttpException
     */
    private function updateContact(array $requestBody, int $contactId): void
    {
        if (! empty($requestBody['username'])) {
            $this->assertUniqueUsername($requestBody['username'], $contactId);
        }

        (new ContactRepository(Database::get()))->update($this->createContactData($requestBody, $contactId));
    }

    /**
     * Transform the given request body into what the {@see ContactRepository} expects
     *
     * @param requestBody $requestBody
     * @param ?int $contactId The id of the contact to update, NULL to create a new one
     *
     * @return ContactData
     *
     * @throws HttpException If a referenced contact group does not exist
     */
    private function createContactData(array $requestBody, ?int $contactId = null): ContactData
    {
        $groups = [];
        foreach ($requestBody['groups'] ?? [] as $groupIdentifier) {
            $groupId = ContactGroups::getGroupId($groupIdentifier);

            if ($groupId === null) {
                throw new HttpException(
                    422,
                    sprintf('Contact Group with identifier %s does not exist', $groupIdentifier)
                );
            }

            $groups[] = $groupId;
        }

        return new ContactData(
            id: $contactId,
            fullName: $requestBody['full_name'],
            username: $requestBody['username'] ?? null,
            // The channel's existence is verified by assertValidRequestBody()
            channelId: (int) Channels::getChannelId($requestBody['default_channel']),
            addresses: $requestBody['addresses'] ?? [],
            groups: $groups,
            externalUuid: $requestBody['id']
        );
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
    private function assertUniqueUsername(string $username, ?int $contactId = null): void
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

        foreach (self::REQUIRED_FIELD_TYPES as $field => $type) {
            if (empty($requestBody[$field])) {
                throw new HttpException(422, $msgPrefix . "the field $field must be present");
            }

            if ($type === 'string' && ! is_string($requestBody[$field])) {
                throw new HttpException(422, $msgPrefix . "expects $field to be of type string");
            }
        }

        if (! Uuid::isValid($requestBody['id'])) {
            throw new HttpBadRequestException($msgPrefix . 'given id is not a valid UUID');
        }

        if (! Uuid::isValid($requestBody['default_channel'])) {
            throw new HttpException(422, $msgPrefix . 'given default_channel is not a valid UUID');
        }

        $channelId = Channels::getChannelId($requestBody['default_channel']);

        if ($channelId === false) {
            throw new HttpException(
                422,
                sprintf('Channel with identifier %s does not exist', $requestBody['default_channel'])
            );
        }

        $channelType = Channels::getChannelType($channelId);

        if ($channelType === 'webhook') {
            // pass
        } elseif (
            ! isset($requestBody['addresses'])
            || ! is_array($requestBody['addresses'])
            || empty($requestBody['addresses'][$channelType])
        ) {
            throw new HttpException(
                422,
                $msgPrefix . "an address according to default_channel type $channelType is required"
            );
        }

        $addressTypes = array_keys($requestBody['addresses'] ?? []);
        if (! empty($addressTypes)) {
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
        }

        if (! empty($requestBody['username']) && ! is_string($requestBody['username'])) {
            throw new HttpException(422, $msgPrefix . 'expects username to be of type string');
        }

        if (! empty($requestBody['groups'])) {
            if (! is_array($requestBody['groups'])) {
                throw new  HttpException(422, $msgPrefix . 'expects groups to be of type array');
            }

            foreach ($requestBody['groups'] as $group) {
                if (! is_string($group)) {
                    throw new HttpException(422, $msgPrefix . 'an invalid group identifier format given');
                } elseif (! Uuid::isValid($group)) {
                    throw new HttpException(
                        422,
                        sprintf($msgPrefix . 'the group identifier %s is not a valid UUID', $group)
                    );
                }
            }
        }
    }

    /**
     * Fetch the user(contact) identifiers of the Contact Group with the given id from the contactgroup_member table
     *
     * @param int $contactgroupId
     *
     * @return string[]
     */
    public static function fetchUserIdentifiers(int $contactgroupId): array
    {
        $contactsUUIDs = Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('co.external_uuid')
                ->joinLeft('contact co', 'co.id = cgm.contact_id')
                ->where(['cgm.contactgroup_id = ?' => $contactgroupId, 'cgm.deleted = ?' => 'n'])
                ->groupBy('co.external_uuid')
        );

        return array_map(static::getUUIDString(...), $contactsUUIDs);
    }
}
