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
    /**
     * The route to handle a contactgroup with a specific identifier
     *
     * @var string
     */
    public const ROUTE_WITH_IDENTIFIER = '/contactgroups/{identifier}';
    /**
     * The route to handle multiple contactgroup or create contactgroup
     *
     * @var string
     */
    public const ROUTE_WITHOUT_IDENTIFIER = '/contactgroups';

    /**
     * Get a contactgroup by UUID.
     *
     * @param string $identifier
     * @return array
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    public function get(string $identifier): array
    {
        $stmt = $this->createSelectStmt();

        $stmt->where(['external_uuid = ?' => $identifier]);

        /** @var stdClass|false $result */
        $result = $this->getDB()->fetchOne($stmt);

        if (empty($result)) {
            throw HttpNotFoundException::create(['Contactgroup not found']);
        }

        $this->createGETRowFinalizer()($result);

        return $this->createArrayOfResponseData(body: Json::sanitize($result));
    }

    /**
     * List contactgroups or get specific contactgroups by filter parameters.
     *
     * @param string $filterStr
     * @return array
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
    public function getPlural(string $filterStr): array
    {
        $stmt = $this->createSelectStmt();

        $filter = $this->createFilterFromFilterStr(
            $filterStr,
            $this->createFilterRuleListener(
                ['id', 'name'],
                'external_uuid'
            )
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        return $this->createArrayOfResponseData(
            body: $this->createContentGenerator($this->getDB(), $stmt, $this->createGETRowFinalizer())
        );
    }

    /**
     * Update a contactgroup by UUID.
     *
     * @param string $identifier
     * @param requestBody $requestBody
     * @return array
     * @throws HttpBadRequestException
     * @throws HttpException
     * @throws HttpNotFoundException
     */
    public function put(string $identifier, array $requestBody): array
    {
        if (empty($identifier)) {
            throw HttpBadRequestException::create(['Identifier is required']);
        }

        $data = $this->getValidatedRequestBodyData($requestBody);

        if ($identifier !== $data['id']) {
            throw HttpBadRequestException::create(['Identifier mismatch']);
        }

        $this->getDB()->beginTransaction();

        if (($contactgroupId = self::getGroupId($identifier)) !== null) {
            if (! empty($data['name'])) {
                $this->assertUniqueName($data['name'], $contactgroupId);
            }

            $this->getDB()->update(
                'contactgroup',
                ['name' => $data['name']],
                ['id = ?' => $contactgroupId]
            );
            $this->getDB()->update(
                'contactgroup_member',
                ['deleted' => 'y'],
                ['contactgroup_id = ?' => $contactgroupId, 'deleted = ?' => 'n']
            );

            if (! empty($data['users'])) {
                $this->addUsers($contactgroupId, $data['users']);
            }

            $responseCode = 204;
        } else {
            $this->addContactgroup($data);
            $responseCode = 201;
            $responseBody = '{"status":"success","message":"Contactgroup created successfully"}';
        }

        $this->getDB()->commitTransaction();

        return $this->createArrayOfResponseData(statusCode: $responseCode, body: $responseBody ?? null);
    }

    /**
     * Create or replace a contactgroup
     *
     * @param string|null $identifier The identifier of the contactgroup to update, or null to create a new one
     * @param requestBody $requestBody The request body containing the contactgroup data
     *
     * @return array The response data
     *
     * @throws HttpBadRequestException if the request body is invalid
     * @throws HttpNotFoundException if the contactgroup to update does not exist
     * @throws HttpException if a contactgroup with the given identifier already exists
     */
    public function post(?string $identifier, array $requestBody): array
    {
        $data = $this->getValidatedRequestBodyData($requestBody);

        $this->getDB()->beginTransaction();

        if ($identifier === null) {
            if (self::getGroupId($data['id'])) {
                throw new HttpException(422, 'Contactgroup already exists');
            }

            $this->addContactgroup($data);
        } else {
            $contactgroupId = self::getGroupId($identifier);
            if ($contactgroupId === null) {
                throw HttpNotFoundException::create(['Contactgroup not found']);
            }

            if ($identifier === $data['id'] || self::getGroupId($data['id']) !== null) {
                throw new HttpException(422, 'Contactgroup already exists');
            }

            $this->removeContactgroup($contactgroupId);
            $this->addContactgroup($data);
        }

        $this->getDB()->commitTransaction();

        return $this->createArrayOfResponseData(
            statusCode: 201,
            body: '{"status":"success","message":"Contactgroup created successfully"}',
            additionalHeaders: [
                'Location' => 'notifications/api/v1' . self::ROUTE_WITHOUT_IDENTIFIER . '/' . $data['id']
            ]
        );
    }

    /**
     * Remove the contactgroup with the given id
     *
     * @param string $identifier
     * @return array
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     */
    public function delete(string $identifier): array
    {
        if (empty($identifier)) {
            throw HttpBadRequestException::create(['Identifier is required']);
        }

        if (($contactgroupId = self::getGroupId($identifier)) === null) {
            throw HttpNotFoundException::create(['Contactgroup not found']);
        }

        $this->getDB()->beginTransaction();
        $this->removeContactgroup($contactgroupId);
        $this->getDB()->commitTransaction();

        return $this->createArrayOfResponseData(statusCode: 204);
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
     *
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

    private function removeContactgroup(int $id): void
    {
        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];
        $updateCondition = ['contactgroup_id = ?' => $id, 'deleted = ?' => 'n'];

        $rotationAndMemberIds = $this->getDB()->fetchPairs(
            RotationMember::on($this->getDB())
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contactgroup_id', $id))
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
                    ->filter(Filter::all(
                        Filter::equal('rotation_id', $rotationIds),
                        Filter::unequal('contactgroup_id', $id)
                    ))->assembleSelect()
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
                ->filter(Filter::equal('contactgroup_id', $id))
                ->assembleSelect()
        );

        $this->getDB()->update('rule_escalation_recipient', $markAsDeleted, $updateCondition);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->getDB()->fetchCol(
                RuleEscalationRecipient::on($this->getDB())
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('contactgroup_id', $id)
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

        $this->getDB()->update(
            'contactgroup',
            $markAsDeleted,
            ['id = ?' => $id, 'deleted = ?' => 'n']
        );
    }

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
            ! isset($data['id'], $data['name'])
            || ! is_string($data['id'])
            || ! is_string($data['name'])
        ) {
            throw HttpBadRequestException::create(
                [$msgPrefix . 'the fields id and name must be present and of type string']
            );
        }

        if (! Uuid::isValid($data['id'])) {
            throw HttpBadRequestException::create([$msgPrefix . 'given id is not a valid UUID']);
        }

        if (! empty($data['users'])) {
            if (! is_array($data['users'])) {
                throw HttpBadRequestException::create([$msgPrefix .  'expects users to be an array']);
            }

            foreach ($data['users'] as $user) {
                if (! is_string($user) || ! Uuid::isValid($user)) {
                    throw HttpBadRequestException::create([$msgPrefix . 'user identifiers must be valid UUIDs']);
                }

                //TODO: check if users exist, here?
            }
        }

        /** @var requestBody $data */
        return $data;
    }

    /**
     * Add a new contactgroup with the given data
     *
     * @param requestBody $data
     *
     * @return void
     * @throws HttpNotFoundException
     */
    private function addContactgroup(array $data): void
    {
        Database::get()->insert('contactgroup', [
            'name'              => $data['name'],
            'external_uuid'     => $data['id'],
            'changed_at'            => (int) (new DateTime())->format("Uv"),
        ]);

        $id = Database::get()->lastInsertId();

        if (! empty($data['users'])) {
            $this->addUsers($id, $data['users']);
        }
    }

    /**
     * Add the given users as contactgroup_member with the given id
     *
     * @param int $contactgroupId
     * @param string[] $users
     *
     * @return void
     * @throws HttpNotFoundException
     */
    private function addUsers(int $contactgroupId, array $users): void
    {
        foreach ($users as $identifier) {
            $contactId = Contacts::getContactId($identifier);
            if ($contactId === null) {
                throw new HttpException(422, sprintf('User with identifier %s not found', $identifier));
            }

            Database::get()->insert('contactgroup_member', [
                'contactgroup_id'   => $contactgroupId,
                'contact_id'        => $contactId,
                'changed_at'        => (int) (new DateTime())->format("Uv"),
            ]);
        }
    }

    /**
     * Create a base Select query for contactgroups
     *
     * @return Select
     */
    private function createSelectStmt(): Select
    {
        return (new Select())
            ->distinct()
            ->from('contactgroup cg')
            ->columns([
                'contactgroup_id'   => 'cg.id',
                'id'                => 'cg.external_uuid',
                'name'
            ]);
    }

    /**
     * Create a finalizer for get rows that enriches the row with additional data or removes irrelevant data
     *
     * @return callable Returns a callable that finalizes the row
     */
    private function createGETRowFinalizer(): callable
    {
        return function (stdClass $row) {
            $row->users = Contacts::fetchUserIdentifiers($row->contactgroup_id);

            unset($row->contactgroup_id);
        };
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
            throw new HttpException(409, 'Username ' . $name . ' already exists');
        }
    }
}
