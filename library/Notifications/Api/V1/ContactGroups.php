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
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Repository\ContactGroupRepository;
use Icinga\Util\Json;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

/** @phpstan-type requestBody array{
 *  id: string,
 *  name: string,
 *  users?: string[],
 *  }
 */
#[OA\Schema(
    schema: 'Contactgroup',
    description: 'A contact group',
    required: ['id', 'name'],
    type: 'object'
)]
#[SchemaUUID(
    entityName: 'Contactgroup',
    example: '81fb569f-5669-4cd6-93bb-9259446b8b23',
)]
#[SchemaUUID(
    entityName: 'NewContactgroup',
    example: '31fb569f-5669-4cd6-93bb-9259446b8b74',
)]
class ContactGroups extends ApiV1 implements RequestHandlerInterface, EndpointInterface
{
    public const REQUIRED_FIELDS = ['id', 'name'];
    public const REQUIRED_FIELD_TYPES = ['id' => 'string', 'name' => 'string'];

    #[OA\Examples(
        example: 'InvalidUserFormat',
        summary: 'Invalid user format',
        value: ['message' => 'Invalid request body: expects users to be an array']
    )]
    #[OA\Examples(
        example: 'InvalidUserUUID',
        summary: 'Invalid user UUID',
        value: ['message' => 'Invalid request body: the user identifier X is not a valid UUID']
    )]
    #[OA\Examples(
        example: 'NameAlreadyExists',
        summary: 'Name already exists',
        value: ['message' => 'Name x already exists']
    )]
    #[OA\Examples(
        example: 'UserNotExists',
        summary: 'User does not exist',
        value: ['message' => 'User with identifier x not found']
    )]
    protected array $specificResponses = [];
    #[OA\Property(
        ref: '#/components/schemas/ContactgroupUUID',
    )]
    protected string $id;
    #[OA\Property(
        description: 'The name of the Contact Group',
        type: 'string',
        example: 'My Contact Group',
    )]
    protected string $name;
    #[OA\Property(
        description: 'List of user identifiers (UUIDs) that belong to this Contact Group',
        type: 'array',
        items: new OA\Items(ref: '#/components/schemas/ContactUUID')
    )]
    protected ?array $users;


    public function getEndpoint(): string
    {
        return 'contact-groups';
    }

    /**
     * Get a Contact Group by UUID.
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
        entityName: 'Contactgroup',
        path: '/contact-groups/{identifier}',
        description: 'Retrieve detailed information about a specific notification Contact Group using its UUID',
        summary: 'Get a specific Contact Group by its UUID',
        tags: ['Contact Groups'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Contact Group to retrieve',
                identifierSchema: 'ContactgroupUUID'
            ),
        ],
        responses: []
    )]
    public function get(?string $identifier, string $queryFilter): ResponseInterface
    {
        $stmt = (new Select())
            ->distinct()
            ->from('contactgroup cg')
            ->columns([
                'contactgroup_id' => 'cg.id',
                'id'              => 'cg.external_uuid',
                'name'
            ])
            ->where(['cg.deleted = ?' => 'n']);

        if ($identifier === null) {
            return $this->getPlural($queryFilter, $stmt);
        }

        $stmt->where(['external_uuid = ?' => static::transformUUIDForDB(Database::get(), $identifier)]);

        /** @var stdClass|false $result */
        $result = Database::get()->fetchOne($stmt);

        if ($result === false) {
            throw new HttpNotFoundException('Contact Group not found');
        }

        $this->prepareRow($result);

        return $this->createResponse(body: Json::sanitize(['data' => $result]));
    }

    /**
     * List Contact Groups or get specific Contact Groups by filter parameters.
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
        entityName: 'Contactgroup',
        path: '/contact-groups',
        description: 'Retrieve all Contact Groups or filter them by parameters.',
        summary: 'List all Contact Groups or filter by parameters',
        tags: ['Contact Groups'],
        parameters: [
            new QueryParameter(
                name: 'id',
                description: 'Filter by Contact Group UUID',
                schema: new SchemaUUID(entityName: 'Contactgroup'),
            ),
            new QueryParameter(
                name: 'name',
                description: 'Filter by Contact Group name',
            ),
        ],
        responses: []
    )]
    private function getPlural(string $queryFilter, Select $stmt): ResponseInterface
    {
        $filter = $this->assembleFilter(
            $queryFilter,
            ['id', 'name'],
            'external_uuid'
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        return $this->createResponse(body: $this->createContentGenerator($stmt));
    }

    /**
     * Update a Contact Group by UUID.
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
        entityName: 'Contactgroup',
        path: '/contact-groups/{identifier}',
        description: 'Update a Contact Group by UUID, if it doesn\'t exist, it will be created. \
        The identifier must be the same as the payload id',
        summary: 'Update a Contact Group by UUID',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/Contactgroup'
            )
        ),
        tags: ['Contact Groups'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Contact Group to update',
                identifierSchema: 'ContactgroupUUID'
            )
        ],
        examples422: [
            new ResponseExample('InvalidUserFormat'),
            new ResponseExample('InvalidUserUUID'),
            new ResponseExample('NameAlreadyExists'),
            new ResponseExample('UserNotExists'),
        ]
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

        Database::get()->beginTransaction();

        if (($contactgroupId = self::getGroupId($identifier)) !== null) {
            $this->updateContactgroup($requestBody, $contactgroupId);
            $result = $this->createResponse(204);
        } else {
            $this->addContactgroup($requestBody);
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
                Json::sanitize(['message' => 'Contact Group created successfully'])
            );
        }

        Database::get()->commitTransaction();

        return $result;
    }

    /**
     * Create or replace a Contact Group
     *
     * @param string|null $identifier The identifier of the Contact Group to update, or null to create a new one
     * @param requestBody $requestBody The request body containing the Contact Group data
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     * @throws HttpException
     * @throws JsonEncodeException
     */
    #[OadV1Post(
        entityName: 'Contactgroup',
        path: '/contact-groups',
        description: 'Create a new Contact Group',
        summary: 'Create a new Contact Group',
        tags: ['Contact Groups'],
        examples422: [
            new ResponseExample('InvalidUserFormat'),
            new ResponseExample('InvalidUserUUID'),
            new ResponseExample('NameAlreadyExists'),
            new ResponseExample('UserNotExists'),
        ]
    )]
    #[OadV1Post(
        entityName: 'Contactgroup',
        path: '/contact-groups/{identifier}',
        description: 'Replace a Contact Group by UUID, the identifier must be different from the payload id',
        summary: 'Replace a Contact Group by UUID',
        tags: ['Contact Groups'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Contact Group to create',
                identifierSchema: 'ContactgroupUUID'
            )
        ],
        examples422: [
            new ResponseExample('InvalidUserFormat'),
            new ResponseExample('InvalidUserUUID'),
            new ResponseExample('NameAlreadyExists'),
            new ResponseExample('UserNotExists'),
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

            $groupId = $this->getGroupId($identifier);

            if ($groupId === null) {
                throw new HttpNotFoundException('Contact Group not found');
            }
        }

        if ($this->getGroupId($requestBody['id']) !== null) {
            throw new HttpException(422, 'Contact Group already exists');
        }

        if (! $emptyIdentifier) {
            (new ContactGroupRepository(Database::get()))->delete($groupId);
        }

        $this->addContactgroup($requestBody);
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
            Json::sanitize(['message' => 'Contact Group created successfully'])
        );
    }

    /**
     * Remove the Contact Group with the given id
     *
     * @param string $identifier
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     */
    #[OadV1Delete(
        entityName: 'Contactgroup',
        path: '/contact-groups/{identifier}',
        description: 'Delete a Contact Group by UUID',
        summary: 'Delete a Contact Group by UUID',
        tags: ['Contact Groups'],
    )]
    public function delete(string $identifier): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        $contactgroupId = self::getGroupId($identifier);

        if ($contactgroupId === null) {
            throw new HttpNotFoundException('Contact Group not found');
        }

        Database::get()->beginTransaction();
        (new ContactGroupRepository(Database::get()))->delete($contactgroupId);
        Database::get()->commitTransaction();

        return $this->createResponse(204);
    }

    /**
     * Fetch the group identifiers of the contact with the given id from the contactgroup_member table
     *
     * @param int $contactId
     *
     * @return string[]
     */
    public static function fetchGroupIdentifiers(int $contactId): array
    {
        $groupsUUIDs = Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('cg.external_uuid')
                ->joinLeft('contactgroup cg', 'cg.id = cgm.contactgroup_id')
                ->where(['cgm.contact_id = ?' => $contactId, 'cgm.deleted = ?' => 'n'])
                ->groupBy('cg.external_uuid')
        );

        return array_map(static::getUUIDString(...), $groupsUUIDs);
    }

    /**
     * Get the group id with the given identifier
     *
     * @param string $identifier
     *
     * @return ?int
     */
    public static function getGroupId(string $identifier): ?int
    {
        /** @var stdClass|false $group */
        $group = Database::get()->fetchOne(
            (new Select())
                ->from('contactgroup')
                ->columns('id')
                ->where([
                    'external_uuid = ?' => static::transformUUIDForDB(Database::get(), $identifier),
                    'deleted = ?' => 'n'
                ])
        );
//
//        if ($group === false) {
//            $deletedGroup = Database::get()
//                ->fetchCol('SELECT id FROM contactgroup WHERE external_uuid = ?', [$identifier]);
//
//            if (! empty($deletedGroup)) {
//                throw new HttpException(422, 'Contactgroup id is not available: ' . $identifier);
//            }
//        }

        return $group->id ?? null;
//        $group = Database::get()
//            ->fetchCol('SELECT id FROM contactgroup WHERE external_uuid = ?', [$identifier]);
//
//        return $group[0] ?? null;
    }

    /**
     * Validate the request body for required fields and types
     *
     * @param requestBody $requestBody
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
            throw new HttpException(422, $msgPrefix . 'given id is not a valid UUID');
        }

        if (! empty($requestBody['users'])) {
            if (! is_array($requestBody['users'])) {
                throw new HttpException(422, $msgPrefix . 'expects users to be an array');
            }

            foreach ($requestBody['users'] as $user) {
                if (! is_string($user) || ! Uuid::isValid($user)) {
                    throw new HttpException(422, sprintf(
                        '%sthe user identifier %s is not a valid UUID',
                        $msgPrefix,
                        $user
                    ));
                }
                //TODO: check if users exist, here?
            }
        }
    }

    /**
     * Add a new Contact Group with the given data
     *
     * @param requestBody $requestBody
     *
     * @return void
     *
     * @throws HttpException
     */
    private function addContactgroup(array $requestBody): void
    {
        if (! empty($requestBody['name'])) {
            $this->assertUniqueName($requestBody['name']);
        }

        (new ContactGroupRepository(Database::get()))->create($this->createContactGroupData($requestBody));
    }

    /**
     * Update the Contact Group with the given id with the given data
     *
     * @param requestBody $requestBody
     * @param int $contactgroupId
     *
     * @return void
     *
     * @throws HttpException
     */
    private function updateContactgroup(array $requestBody, int $contactgroupId): void
    {
        if (! empty($requestBody['name'])) {
            $this->assertUniqueName($requestBody['name'], $contactgroupId);
        }

        (new ContactGroupRepository(Database::get()))
            ->update($this->createContactGroupData($requestBody, $contactgroupId));
    }

    /**
     * Transform the given request body into what the {@see ContactGroupRepository} expects
     *
     * @param requestBody $requestBody
     * @param ?int $contactgroupId The id of the Contact Group to update, NULL to create a new one
     *
     * @return ContactGroupData
     *
     * @throws HttpException If a referenced user does not exist
     */
    private function createContactGroupData(array $requestBody, ?int $contactgroupId = null): ContactGroupData
    {
        $members = [];
        foreach ($requestBody['users'] ?? [] as $identifier) {
            $contactId = Contacts::getContactId($identifier);

            if ($contactId === null) {
                throw new HttpException(422, sprintf('User with identifier %s not found', $identifier));
            }

            $members[] = $contactId;
        }

        return new ContactGroupData(
            id: $contactgroupId,
            name: $requestBody['name'],
            members: $members,
            externalUuid: $requestBody['id']
        );
    }

    public function prepareRow(stdClass $row): void
    {
        $row->id = static::getUUIDString($row->id);
        $row->users = Contacts::fetchUserIdentifiers($row->contactgroup_id);

        unset($row->contactgroup_id);
    }

    /**
     * Assert that the name is unique
     *
     * @param string $name
     * @param ?int $contactgroupId The id of the Contact Group to exclude
     *
     * @return void
     *
     * @throws HttpException if the username already exists
     */
    private function assertUniqueName(string $name, ?int $contactgroupId = null): void
    {
        $stmt = (new Select())
            ->from('contactgroup')
            ->columns('1')
            ->where([
                'name = ?' => $name,
                'deleted = ?' => 'n'
            ]);

        if ($contactgroupId) {
            $stmt->where(['id != ?' => $contactgroupId]);
        }

        $user = Database::get()->fetchOne($stmt);

        if ($user) {
            throw new HttpException(422, sprintf('Name %s already exists', $name));
        }
    }
}
