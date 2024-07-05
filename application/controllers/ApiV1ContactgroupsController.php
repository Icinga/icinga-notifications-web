<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Exception;
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
use stdClass;

/** @phpstan-type requestBody array{
 *  id: string,
 *  name: string,
 *  users?: string[],
 *  }
 */
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

        /** @var ?string $identifier */
        $identifier = $request->getParam('identifier');

        if ($identifier && ! Uuid::isValid($identifier)) {
            $this->httpBadRequest('The given identifier is not a valid UUID');
        }

        $filterStr = rawurldecode(Url::fromRequest()->getQueryString());
        if ($method !== 'GET' && $filterStr) {
            $this->httpBadRequest('Filter is only allowed for GET requests');
        }

        $filter = FilterProcessor::assembleFilter(
            QueryString::fromString($filterStr)
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

                    /** @var stdClass|false $result */
                    $result = $db->fetchOne($stmt);

                    if ($result === false) {
                        $this->httpNotFound('Contactgroup not found');
                    }

                    $result->users = $this->fetchUserIdentifiers($result->contactgroup_id);

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
                    /** @var stdClass $row */
                    foreach ($res as $i => $row) {
                        $row->users = $this->fetchUserIdentifiers($row->contactgroup_id);

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

                $data = $this->getValidatedData();

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

                $data = $this->getValidatedData();

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
     * @return string[]
     */
    private function fetchUserIdentifiers(int $contactgroupId): array
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
        /** @var stdClass|false $user */
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
        /** @var stdClass|false $contactgroup */
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
     * @param requestBody $data
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
     * Get the validated POST|PUT request data
     *
     * @return requestBody
     *
     * @throws HttpBadRequestException if the request body is invalid
     */
    private function getValidatedData(): array
    {
        $msgPrefix = 'Invalid request body: ';

        try {
            $data = $this->getRequest()->getPost();
        } catch (Exception $e) {
            $this->httpBadRequest($msgPrefix . 'given content is not a valid JSON');
        }

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
}
