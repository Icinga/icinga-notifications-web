<?php

namespace Icinga\Module\Notifications\Api\V1;

use DateTime;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Util\Json;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
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
    properties: [
        new OA\Property(
            property: 'id',
            ref: '#/components/schemas/ContactGroupUUID',
            description: 'The UUID of the contactgroup'
        ),
        new OA\Property(
            property: 'name',
            description: 'The full name of the contactgroup',
            type: 'string'
        ),
        new OA\Property(
            property: 'users',
            description: 'List of user identifiers (UUIDs) that belong to this contactgroup',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ContactUUID')
        )
    ],
    type: 'object'
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
    public function getEndpoint(): string
    {
        return 'contact-groups';
    }

    /**
     * Get a contactgroup by UUID.
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
    public function get(?string $identifier, string $queryFilter): ResponseInterface
    {
        $stmt = (new Select())
            ->distinct()
            ->from('contactgroup cg')
            ->columns([
                'contactgroup_id' => 'cg.id',
                'id'              => 'cg.external_uuid',
                'name'
            ]);

        if ($identifier === null) {
            return $this->getPlural($queryFilter, $stmt);
        }

        $stmt->where(['external_uuid = ?' => $identifier]);

        /** @var stdClass|false $result */
        $result = Database::get()->fetchOne($stmt);

        if ($result === false) {
            throw new HttpNotFoundException('Contactgroup not found');
        }

        $this->prepareRow($result);

        return $this->createResponse(body: Json::sanitize(['data' => [$result]]));
    }

    /**
     * List contactgroups or get specific contactgroups by filter parameters.
     *
     * @param string $queryFilter
     * @param Select $stmt
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
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
     * Update a contactgroup by UUID.
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
            if (! empty($requestBody['name'])) {
                $this->assertUniqueName($requestBody['name'], $contactgroupId);
            }

            Database::get()->update(
                'contactgroup',
                ['name' => $requestBody['name']],
                ['id = ?' => $contactgroupId]
            );
            Database::get()->update(
                'contactgroup_member',
                ['deleted' => 'y'],
                ['contactgroup_id = ?' => $contactgroupId, 'deleted = ?' => 'n']
            );

            if (! empty($requestBody['users'])) {
                $this->addUsers($contactgroupId, $requestBody['users']);
            }

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
                    )
                ],
                Json::sanitize(['message' => 'Contactgroup created successfully'])
            );
        }

        Database::get()->commitTransaction();

        return $result;
    }

    /**
     * Create or replace a contactgroup
     *
     * @param string|null $identifier The identifier of the contactgroup to update, or null to create a new one
     * @param requestBody $requestBody The request body containing the contactgroup data
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     * @throws HttpException
     * @throws JsonEncodeException
     */
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
                throw new HttpNotFoundException('Contactgroup not found');
            }
        }

        if ($this->getGroupId($requestBody['id']) !== null) {
            throw new HttpException(422, 'Contactgroup already exists');
        }

        if (! $emptyIdentifier) {
            $this->removeContactgroup($groupId);
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
                )
            ],
            Json::sanitize(['message' => 'Contactgroup created successfully'])
        );
    }

    /**
     * Remove the contactgroup with the given id
     *
     * @param string $identifier
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     */
    public function delete(string $identifier): ResponseInterface
    {
        if (empty($identifier)) {
            throw new HttpBadRequestException('Identifier is required');
        }

        $contactgroupId = self::getGroupId($identifier);

        if ($contactgroupId === null) {
            throw new HttpNotFoundException('Contactgroup not found');
        }

        Database::get()->beginTransaction();
        $this->removeContactgroup($contactgroupId);
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
        return Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('cg.external_uuid')
                ->joinLeft('contactgroup cg', 'cg.id = cgm.contactgroup_id')
                ->where(['cgm.contact_id = ?' => $contactId])
                ->groupBy('cg.external_uuid')
        );
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
                ->where(['external_uuid = ?' => $identifier])
        );

        return $group->id ?? null;
    }

    /**
     * Remove the contactgroup with the given id and all its references
     *
     * @param int $id
     *
     * @return void
     */
    private function removeContactgroup(int $id): void
    {
        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];
        $updateCondition = ['contactgroup_id = ?' => $id, 'deleted = ?' => 'n'];

        $rotationAndMemberIds = Database::get()->fetchPairs(
            RotationMember::on(Database::get())
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contactgroup_id', $id))
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
                            Filter::unequal('contactgroup_id', $id)
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
                ->filter(Filter::equal('contactgroup_id', $id))
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
                            Filter::unequal('contactgroup_id', $id)
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

        Database::get()->update(
            'contactgroup',
            $markAsDeleted,
            ['id = ?' => $id, 'deleted = ?' => 'n']
        );
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

        if (
            ! isset($requestBody['id'], $requestBody['name'])
            || ! is_string($requestBody['id'])
            || ! is_string($requestBody['name'])
        ) {
            throw new HttpException(
                422,
                $msgPrefix . 'the fields id and name must be present and of type string'
            );
        }

        if (! Uuid::isValid($requestBody['id'])) {
            throw new HttpBadRequestException($msgPrefix . 'given id is not a valid UUID');
        }

        if (! empty($requestBody['users'])) {
            if (! is_array($requestBody['users'])) {
                throw new HttpBadRequestException($msgPrefix . 'expects users to be an array');
            }

            foreach ($requestBody['users'] as $user) {
                if (! is_string($user) || ! Uuid::isValid($user)) {
                    throw new HttpBadRequestException($msgPrefix . 'user identifiers must be valid UUIDs');
                }
                //TODO: check if users exist, here?
            }
        }
    }

    /**
     * Add a new contactgroup with the given data
     *
     * @param requestBody $requestBody
     *
     * @return void
     * @throws HttpException
     */
    private function addContactgroup(array $requestBody): void
    {
        Database::get()->insert('contactgroup', [
            'name'          => $requestBody['name'],
            'external_uuid' => $requestBody['id'],
            'changed_at'    => (int) (new DateTime())->format("Uv"),
        ]);

        $id = Database::get()->lastInsertId();

        if (! empty($requestBody['users'])) {
            $this->addUsers($id, $requestBody['users']);
        }
    }

    /**
     * Add the given users as contactgroup_member with the given id
     *
     * @param int $contactgroupId
     * @param string[] $users
     *
     * @return void
     *
     * @throws HttpException
     */
    private function addUsers(int $contactgroupId, array $users): void
    {
        foreach ($users as $identifier) {
            $contactId = Contacts::getContactId($identifier);

            if ($contactId === null) {
                throw new HttpException(422, sprintf('User with identifier %s not found', $identifier));
            }

            Database::get()->insert('contactgroup_member', [
                'contactgroup_id' => $contactgroupId,
                'contact_id'      => $contactId,
                'changed_at'      => (int) (new DateTime())->format("Uv"),
            ]);
        }
    }

    public function prepareRow(stdClass $row): void
    {
        $row->users = Contacts::fetchUserIdentifiers($row->contactgroup_id);

        unset($row->contactgroup_id);
    }

    /**
     * Assert that the name is unique
     *
     * @param string $name
     * @param ?int $contactgroupId The id of the contactgroup to exclude
     *
     * @return void
     *
     * @throws HttpException if the username already exists
     */
    private function assertUniqueName(string $name, int $contactgroupId = null): void
    {
        $stmt = (new Select())
            ->from('contactgroup')
            ->columns('1')
            ->where(['name = ?' => $name]);

        if ($contactgroupId) {
            $stmt->where(['id != ?' => $contactgroupId]);
        }

        $user = Database::get()->fetchOne($stmt);

        if ($user) {
            throw new HttpException(422, sprintf('Username %s already exists', $name));
        }
    }
}
