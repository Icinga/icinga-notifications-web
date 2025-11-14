<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\V1;

use DateTime;
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
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Util\Json;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
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
            $this->removeContact($contactId);
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
        $this->removeContact($contactId);
        Database::get()->commitTransaction();

        return $this->createResponse(204);
    }

    public function prepareRow(stdClass $row): void
    {
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
        $contact = Database::get()->fetchOne(
            (new Select())
                ->from('contact')
                ->columns('id')
                ->where(['external_uuid = ?' => $identifier])
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
                    sprintf('Contact Group with identifier %s does not exist', $groupIdentifier)
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

        Database::get()->insert('contact', [
            'full_name'          => $requestBody['full_name'],
            'username'           => $requestBody['username'] ?? null,
            'default_channel_id' => Channels::getChannelId($requestBody['default_channel']),
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

    private function updateContact(array $requestBody, int $contactId): void
    {
        if (! empty($requestBody['username'])) {
            $this->assertUniqueUsername($requestBody['username'], $contactId);
        }

        $changedAt = (int) (new DateTime())->format("Uv");
        Database::get()->update('contact', [
            'full_name'          => $requestBody['full_name'],
            'username'           => $requestBody['username'] ?? null,
            'default_channel_id' => Channels::getChannelId($requestBody['default_channel']),
            'changed_at'         => $changedAt,
        ], ['id = ?' => $contactId]);

        $markAsDeleted = ['deleted' => 'y'];
        Database::get()->update(
            'contact_address',
            $markAsDeleted,
            ['contact_id = ?' => $contactId, 'deleted = ?' => 'n']
        );

        if (! empty($requestBody['addresses'])) {
            $this->addAddresses($contactId, $requestBody['addresses']);
        }

        $storedValues = $this->fetchDbValues($contactId);
        $storedContacts = [];
        if (! empty($storedValues['group_members'])) {
            $storedContacts = explode(',', $storedValues['group_members']);
        }

        $newContactgroups = [];
        if (! empty($requestBody['groups'])) {
            foreach ($requestBody['groups'] as $identifier) {
                $contactgroupId = ContactGroups::getGroupId($identifier);
                if ($contactgroupId === null) {
                    throw new HttpException(
                        422,
                        sprintf('Contact Group with identifier %s does not exist', $identifier)
                    );
                }
                $newContactgroups[] = $contactgroupId;
            }
        }

        $toDelete = array_diff($storedContacts, $newContactgroups);
        $toAdd = array_diff($newContactgroups, $storedContacts);

        if (! empty($toDelete)) {
            Database::get()->update(
                'contactgroup_member',
                ['changed_at' => $changedAt, 'deleted' => 'y'],
                [
                    'contactgroup_id = ?'   => $toDelete,
                    'contact_id IN (?)'     => $contactId,
                    'deleted = ?'           => 'n'
                ]
            );
        }

        if (! empty($toAdd)) {
            $contactgroupsMarkedAsDeleted = Database::get()->fetchCol(
                (new Select())
                    ->from('contactgroup_member')
                    ->columns(['contactgroup_id'])
                    ->where([
                        'contact_id = ?' => $contactId,
                        'deleted = ?' => 'y',
                        'contactgroup_id IN (?)' => $toAdd
                    ])
            );

            $toAdd = array_diff($toAdd, $contactgroupsMarkedAsDeleted);
            foreach ($toAdd as $contactgroupId) {
                Database::get()->insert(
                    'contactgroup_member',
                    [
                        'contactgroup_id' => $contactgroupId,
                        'contact_id' => $contactId,
                        'changed_at' => $changedAt
                    ]
                );
            }

            if (! empty($contactgroupsMarkedAsDeleted)) {
                Database::get()->update(
                    'contactgroup_member',
                    ['changed_at' => $changedAt, 'deleted' => 'n'],
                    [
                        'contact_id = ?' => $contactId,
                        'contactgroup_id IN (?)' => $contactgroupsMarkedAsDeleted
                    ]
                );
            }
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
        $markEntityAsDeleted = array_merge(
            $markAsDeleted,
            ['external_uuid' => substr_replace(Uuid::uuid4()->toString(), '0', 14, 1)]
        );
        $updateCondition = ['contact_id = ?' => $id, 'deleted = ?' => 'n'];

        $rotationAndMemberIds = Database::get()->fetchPairs(
            RotationMember::on(Database::get())
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        $rotationMemberIds = array_keys($rotationAndMemberIds);
        $rotationIds = array_values($rotationAndMemberIds);

        Database::get()->update('rotation_member', $markAsDeleted + ['position' => null], $updateCondition);

        if (! empty($rotationMemberIds)) {
            Database::get()->update(
                'timeperiod_entry',
                $markAsDeleted,
                ['rotation_member_id IN (?)' => $rotationMemberIds, 'deleted = ?' => 'n']
            );
        }

        if (! empty($rotationIds)) {
            $rotationIdsWithOtherMembers = Database::get()->fetchCol(
                RotationMember::on(Database::get())
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
                $rotations = Rotation::on(Database::get())
                    ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
                    ->filter(Filter::equal('id', $toRemoveRotations));

                /** @var Rotation $rotation */
                foreach ($rotations as $rotation) {
                    $rotation->delete();
                }
            }
        }

        $escalationIds = Database::get()->fetchCol(
            RuleEscalationRecipient::on(Database::get())
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('contact_id', $id))
                ->assembleSelect()
        );

        Database::get()->update('rule_escalation_recipient', $markAsDeleted, $updateCondition);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = Database::get()->fetchCol(
                RuleEscalationRecipient::on(Database::get())
                    ->columns('rule_escalation_id')
                    ->filter(
                        Filter::all(
                            Filter::equal('rule_escalation_id', $escalationIds),
                            Filter::unequal('contact_id', $id)
                        )
                    )->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                Database::get()->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        Database::get()->update('contactgroup_member', $markAsDeleted, $updateCondition);
        Database::get()->update('contact_address', $markAsDeleted, $updateCondition);

        Database::get()->update('contact', $markEntityAsDeleted + ['username' => null], ['id = ?' => $id]);
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
        return Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('co.external_uuid')
                ->joinLeft('contact co', 'co.id = cgm.contact_id')
                ->where(['cgm.contactgroup_id = ?' => $contactgroupId, 'cgm.deleted = ?' => 'n'])
                ->groupBy('co.external_uuid')
        );
    }

    /**
     * Fetch the values from the database
     *
     * @param int $contactId
     * @return array
     *
     * @throws HttpNotFoundException
     */
    private function fetchDbValues(int $contactId): array
    {
        $query = Contact::on(Database::get())
            ->columns(['id', 'full_name', 'default_channel_id'])
            ->filter(Filter::equal('id', $contactId));

        /** @var ?Contact $contact */
        $contact = $query->first();
        if ($contact === null) {
            throw new HttpNotFoundException('Contact contact not found');
        }

        $groupMembers = [];
        foreach ($contact->contactgroup_member as $group) {
            $groupMembers[] = $group->contactgroup_id;
        }

        return [
            'group_members' => implode(',', $groupMembers)
        ];
    }
}
