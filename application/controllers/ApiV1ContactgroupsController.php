<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Environment;
use Icinga\Util\Json;
use ipl\Sql\Compat\FilterProcessor;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use Ramsey\Uuid\Uuid;

class ApiV1ContactgroupsController extends CompatController
{
    private const ENDPOINT = 'notifications/api/v1/contactgroups';

    public function indexAction(): void
    {
        $this->assertPermission('notifications/api/v1');

        $request = $this->getRequest();
        if (! $request->isApiRequest()) {
            $this->httpBadRequest('No API request');
        }

        $method = $request->getMethod();
        if (
            in_array($method, ['POST', 'PUT'])
            && (
                ! preg_match('/([^;]*);?/', $request->getHeader('Content-Type'), $matches)
                || $matches[1] !== 'application/json'
            )
        ) {
            $this->httpBadRequest('No JSON content');
        }

        $results = [];
        $responseCode = 200;
        $db = Database::get();
        $identifier = $request->getParam('identifier');

        if ($identifier && ! Uuid::isValid($identifier)) {
            $this->httpBadRequest('The given identifier is not a valid UUID');
        }

        $filter = FilterProcessor::assembleFilter(
            QueryString::fromString(rawurldecode(Url::fromRequest()->getQueryString()))
                ->on(
                    QueryString::ON_CONDITION,
                    function (Filter\Condition $condition) {
                        $column = $condition->getColumn();
                        if (! in_array($column, ['id', 'name'])) {
                            $this->httpBadRequest(sprintf(
                                'Invalid filter column %s given, only id and name are allowed',
                                $column
                            ));
                        }

                        if ($column === 'id') {
                            if (! Uuid::isValid($condition->getValue())) {
                                $this->httpBadRequest('The given filter id is not a valid UUID');
                            }

                            $condition->setColumn('external_uuid');
                        }
                    }
                )->parse()
        );

        switch ($method) {
            case 'GET':
                $stmt = (new Select())
                    ->distinct()
                    ->from('contactgroup cg')
                    ->columns([
                        'contactgroup_id'   => 'cg.id',
                        'id'                => 'cg.external_uuid',
                        'name'
                    ]);

                if ($identifier !== null) {
                    $stmt->where(['external_uuid = ?' => $identifier]);
                    $result = $db->fetchOne($stmt);

                    if ($result === false) {
                        $this->httpNotFound('Contactgroup not found');
                    }

                    $users = $this->fetchUserIdentifiers($result->contactgroup_id);
                    if ($users) {
                        $result->users = $users;
                    }

                    unset($result->contactgroup_id);
                    $results[] = $result;

                    break;
                }

                if ($filter !== null) {
                    $stmt->where($filter);
                }

                $stmt->limit(500);
                $offset = 0;

                ob_end_clean();
                Environment::raiseExecutionTime();

                $this->getResponse()
                    ->setHeader('Content-Type', 'application/json')
                    ->setHeader('Cache-Control', 'no-store')
                    ->sendResponse();

                echo '[';

                $res = $db->select($stmt->offset($offset));
                do {
                    foreach ($res as $i => $row) {
                        $users = $this->fetchUserIdentifiers($row->contactgroup_id);
                        if ($users) {
                            $row->users = $users;
                        }

                        if ($i > 0 || $offset !== 0) {
                            echo ",\n";
                        }

                        unset($row->contactgroup_id);

                        echo Json::sanitize($row);
                    }

                    $offset += 500;
                    $res = $db->select($stmt->offset($offset));
                } while ($res->rowCount());

                echo ']';

                exit;
            case 'POST':
                if ($filter !== null) {
                    $this->httpBadRequest('Cannot filter on POST');
                }

                $data = $request->getPost();

                $this->assertValidData($data);

                $db->beginTransaction();

                if ($identifier === null) {
                    if ($this->getContactgroupId($data['id']) !== null) {
                        throw new HttpException(422, 'Contactgroup already exists');
                    }

                    $this->addContactgroup($data);
                } else {
                    $contactgroupId = $this->getContactgroupId($identifier);
                    if ($contactgroupId === null) {
                        $this->httpNotFound('Contactgroup not found');
                    }

                    if ($identifier === $data['id'] || $this->getContactgroupId($data['id']) !== null) {
                        throw new HttpException(422, 'Contactgroup already exists');
                    }

                    $this->removeContactgroup($contactgroupId);
                    $this->addContactgroup($data);
                }

                $db->commitTransaction();

                $this->getResponse()->setHeader('Location', self::ENDPOINT . '/' . $data['id']);
                $responseCode = 201;

                break;
            case 'PUT':
                if ($identifier === null) {
                    $this->httpBadRequest('Identifier is required');
                }

                $data = $request->getPost();

                $this->assertValidData($data);

                if ($identifier !== $data['id']) {
                    $this->httpBadRequest('Identifier mismatch');
                }

                $db->beginTransaction();

                $contactgroupId = $this->getContactgroupId($identifier);
                if ($contactgroupId !== null) {
                    $db->update('contactgroup', ['name' => $data['name']], ['id = ?' => $contactgroupId]);

                    $db->delete('contactgroup_member', ['contactgroup_id = ?' => $contactgroupId]);

                    if (! empty($data['users'])) {
                        $this->addUsers($contactgroupId, $data['users']);
                    }

                    $responseCode = 204;
                } else {
                    $this->addContactgroup($data);
                    $responseCode = 201;
                }

                $db->commitTransaction();

                break;
            case 'DELETE':
                if ($identifier === null) {
                    $this->httpBadRequest('Identifier is required');
                }

                $db->beginTransaction();

                $contactgroupId = $this->getContactgroupId($identifier);
                if ($contactgroupId === null) {
                    $this->httpNotFound('Contactgroup not found');
                }

                $this->removeContactgroup($contactgroupId);

                $db->commitTransaction();

                $responseCode = 204;

                break;
            default:
                $this->httpBadRequest('Invalid method');
        }

        $this->getResponse()
            ->setHttpResponseCode($responseCode)
            ->json()
            ->setSuccessData($results)
            ->sendResponse();
    }

    /**
     * Fetch the user(contact) identifiers of the contactgroup with the given id
     *
     * @param int $contactgroupId
     *
     * @return ?string[]
     */
    private function fetchUserIdentifiers(int $contactgroupId): ?array
    {
        $users = Database::get()->fetchCol(
            (new Select())
                ->from('contactgroup_member cgm')
                ->columns('co.external_uuid')
                ->joinLeft('contact co', 'co.id = cgm.contact_id')
                ->where(['cgm.contactgroup_id = ?' => $contactgroupId])
                ->groupBy('co.external_uuid')
        );

        return $users ?: null;
    }

    /**
     * Assert that the given user IDs exist
     *
     * @param string $identifier
     *
     * @return int
     *
     * @throws HttpNotFoundException if the user with the given identifier does not exist
     */
    private function getUserId(string $identifier): int
    {
        $user = Database::get()->fetchOne(
            (new Select())
                ->from('contact')
                ->columns('id')
                ->where(['external_uuid = ?' => $identifier])
        );

        if ($user === false) {
            $this->httpNotFound(sprintf('User with identifier %s not found', $identifier));
        }

        return $user->id;
    }

    /**
     * Get the contactgroup id with the given identifier
     *
     * @param string $identifier
     *
     * @return ?int Returns null, if contact does not exist
     */
    private function getContactgroupId(string $identifier): ?int
    {
        $contactgroup =  Database::get()->fetchOne(
            (new Select())
                ->from('contactgroup')
                ->columns('id')
                ->where(['external_uuid = ?' => $identifier])
        );

        return $contactgroup->id ?? null;
    }

    /**
     * Add a new contactgroup with the given data
     *
     * @param array<string, mixed> $data
     *
     * @return void
     */
    private function addContactgroup(array $data): void
    {
        Database::get()->insert('contactgroup', [
            'name'              => $data['name'],
            'external_uuid'     => $data['id']
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
     */
    private function addUsers(int $contactgroupId, array $users): void
    {
        foreach ($users as $identifier) {
            $contactId = $this->getUserId($identifier);

            Database::get()->insert('contactgroup_member', [
                'contactgroup_id'   => $contactgroupId,
                'contact_id'        => $contactId
            ]);
        }
    }

    /**
     * Remove the contactgroup with the given id
     *
     * @param int $id
     *
     * @return void
     */
    private function removeContactgroup(int $id): void
    {
        Database::get()->delete('contactgroup_member', ['contactgroup_id = ?' => $id]);
        Database::get()->delete('contactgroup', ['id = ?' => $id]);
    }

    /**
     * Assert that the given data contains the required fields
     *
     * @param array<string, mixed> $data
     *
     * @throws HttpBadRequestException
     */
    private function assertValidData(array $data): void
    {
        if (! isset($data['id'], $data['name'])) {
            $this->httpBadRequest('The request body must contain the fields id and name');
        }

        if (! Uuid::isValid($data['id'])) {
            $this->httpBadRequest('Given id in request body is not a valid UUID');
        }

        if (! empty($data['users'])) {
            foreach ($data['users'] as $user) {
                if (! Uuid::isValid($user)) {
                    $this->httpBadRequest('User identifiers in request body must be valid UUIDs');
                }
            }
        }
    }
}
