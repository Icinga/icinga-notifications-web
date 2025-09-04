<?php

namespace Icinga\Module\Notifications\Api\V1;

use DateTime;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
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
    public const ROUTE_WITH_IDENTIFIER = '/contacts/{identifier}';
    /**
     * The route to handle multiple contactgroup or create contactgroup
     *
     * @var string
     */
    public const ROUTE_WITHOUT_IDENTIFIER = '/contacts';

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
        $data = $this->getValidatedData($requestBody);

        $this->getDB()->beginTransaction();

        if ($identifier === null) {
            if (self::getGroupId($data['id'])) {
                throw new HttpException(422, 'Contactgroup already exists');
            }

            $this->addContactgroup($data);
        } else {
            $contactgroupId = self::getGroupId($identifier);
            if ($contactgroupId === null) {
                $this->httpNotFound('Contactgroup not found');
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
            body: '{"status": "success","message": "Contactgroup created successfully"}',
            additionalHeaders: [
                'Location' => 'notifications/api/v1' . self::ROUTE_WITHOUT_IDENTIFIER . '/' . $data['id']
            ]
        );
    }

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
        $this->getDB()->beginTransaction();

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

        $this->getDB()->commitTransaction();
    }

    /**
     * Get the validated POST|PUT request data
     *
     * @return requestBody
     *
     * @throws HttpBadRequestException if the request body is invalid
     */
    private function getValidatedData(array $data): array
    {
        $msgPrefix = 'Invalid request body: ';

        if (
            ! isset($data['id'], $data['name'])
            || ! is_string($data['id'])
            || ! is_string($data['name'])
        ) {
            $this->httpBadRequest(
                $msgPrefix . 'the fields id and name must be present and of type string'
            );
        }

        if (! Uuid::isValid($data['id'])) {
            $this->httpBadRequest($msgPrefix . 'given id is not a valid UUID');
        }

        if (! empty($data['users'])) {
            if (! is_array($data['users'])) {
                $this->httpBadRequest($msgPrefix .  'expects users to be an array');
            }

            foreach ($data['users'] as $user) {
                if (! is_string($user) || ! Uuid::isValid($user)) {
                    $this->httpBadRequest($msgPrefix . 'user identifiers must be valid UUIDs');
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
            if ($contactId === false) {
                $this->httpUnprocessableEntity(sprintf('User with identifier %s not found', $identifier));
            }

            Database::get()->insert('contactgroup_member', [
                'contactgroup_id'   => $contactgroupId,
                'contact_id'        => $contactId
            ]);
        }
    }
}
