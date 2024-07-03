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
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Compat\CompatController;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
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
class ApiV1ContactsController extends CompatController
{
    private const ENDPOINT = 'notifications/api/v1/contacts';

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
                        if (! in_array($column, ['id', 'full_name', 'username'])) {
                            $this->httpBadRequest(sprintf(
                                'Invalid filter column %s given, only id, full_name and username are allowed',
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
                    ->from('contact co')
                    ->columns([
                        'contact_id'        => 'co.id',
                        'id'                => 'co.external_uuid',
                        'full_name',
                        'username',
                        'default_channel'   => 'ch.external_uuid',
                    ])
                    ->joinLeft('contact_address ca', 'ca.contact_id = co.id')
                    ->joinLeft('channel ch', 'ch.id = co.default_channel_id');

                if ($identifier !== null) {
                    $stmt->where(['external_uuid = ?' => $identifier]);

                    /** @var stdClass|false $result */
                    $result = $db->fetchOne($stmt);

                    if ($result === false) {
                        $this->httpNotFound('Contact not found');
                    }

                    $result->groups = $this->fetchGroupIdentifiers($result->contact_id);
                    $result->addresses = $this->fetchContactAddresses($result->contact_id);

                    unset($result->contact_id);
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
                        $row->groups = $this->fetchGroupIdentifiers($row->contact_id);
                        $row->addresses = $this->fetchContactAddresses($row->contact_id);

                        if ($i > 0 || $offset !== 0) {
                            echo ",\n";
                        }

                        unset($row->contact_id);

                        echo Json::sanitize($row);
                    }

                    $offset += 500;
                    $res = $db->select($stmt->offset($offset));
                } while ($res->rowCount());

                echo ']';

                exit;
            case 'POST':
                $data = $this->getValidatedData();

                $db->beginTransaction();

                if ($identifier === null) {
                    if ($this->getContactId($data['id']) !== null) {
                        throw new HttpException(422, 'Contact already exists');
                    }

                    $this->addContact($data);
                } else {
                    $contactId = $this->getContactId($identifier);
                    if ($contactId === null) {
                        $this->httpNotFound('Contact not found');
                    }

                    if ($identifier === $data['id'] || $this->getContactId($data['id']) !== null) {
                        throw new HttpException(422, 'Contact already exists');
                    }

                    $this->removeContact($contactId);
                    $this->addContact($data);
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

                $contactId = $this->getContactId($identifier);
                if ($contactId !== null) {
                    if (! empty($data['username'])) {
                        $this->assertUniqueUsername($data['username'], $contactId);
                    }

                    $db->update('contact', [
                        'full_name'             => $data['full_name'],
                        'username'              => $data['username'] ?? null,
                        'default_channel_id'    => $this->getChannelId($data['default_channel'])
                    ], ['id = ?' => $contactId]);

                    $db->delete('contact_address', ['contact_id = ?' => $contactId]);
                    $db->delete('contactgroup_member', ['contact_id = ?' => $contactId]);

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
                }

                $db->commitTransaction();

                break;
            case 'DELETE':
                if ($identifier === null) {
                    $this->httpBadRequest('Identifier is required');
                }

                $db->beginTransaction();

                $contactId = $this->getContactId($identifier);
                if ($contactId === null) {
                    $this->httpNotFound('Contact not found');
                }

                $this->removeContact($contactId);

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
     * Get the channel id with the given identifier
     *
     * @param string $channelIdentifier
     *
     * @return int
     *
     * @throws HttpNotFoundException if the channel does not exist
     */
    private function getChannelId(string $channelIdentifier): int
    {
        /** @var stdClass|false $channel */
        $channel = Database::get()->fetchOne(
            (new Select())
                ->from('channel')
                ->columns('id')
                ->where(['external_uuid = ?' => $channelIdentifier])
        );

        if ($channel === false) {
            $this->httpNotFound('Channel not found');
        }

        return $channel->id;
    }

    /**
     * Fetch the addresses of the contact with the given id
     *
     * @param int $contactId
     *
     * @return string
     */
    private function fetchContactAddresses(int $contactId): string
    {
        /** @var array<string, string> $addresses */
        $addresses = Database::get()->fetchPairs(
            (new Select())
                ->from('contact_address')
                ->columns(['type', 'address'])
                ->where(['contact_id = ?' => $contactId])
        );

        return Json::sanitize($addresses, JSON_FORCE_OBJECT);
    }

    /**
     * Fetch the group identifiers of the contact with the given id
     *
     * @param int $contactId
     *
     * @return string[]
     */
    private function fetchGroupIdentifiers(int $contactId): array
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
     * @return int
     *
     * @throws HttpNotFoundException if the contactgroup with the given identifier does not exist
     */
    private function getGroupId(string $identifier): int
    {
        /** @var stdClass|false $group */
        $group = Database::get()->fetchOne(
            (new Select())
                ->from('contactgroup')
                ->columns('id')
                ->where(['external_uuid = ?' => $identifier])
        );

        if ($group === false) {
            $this->httpNotFound(sprintf('Group with identifier %s not found', $identifier));
        }

        return $group->id;
    }

    /**
     * Get the contact id with the given identifier
     *
     * @param string $identifier
     *
     * @return ?int Returns null, if contact does not exist
     */
    protected function getContactId(string $identifier): ?int
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
     * Add a new contact with the given data
     *
     * @param requestBody $data
     *
     * @return void
     */
    private function addContact(array $data): void
    {
        if (! empty($data['username'])) {
            $this->assertUniqueUsername($data['username']);
        }

        Database::get()->insert('contact', [
            'full_name'             => $data['full_name'],
            'username'              => $data['username'] ?? null,
            'default_channel_id'    => $this->getChannelId($data['default_channel']),
            'external_uuid'         => $data['id']
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
     * Assert that the username is unique
     *
     * @param string $username
     * @param int $contactId The id of the contact to exclude
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

        if ($user !== false) {
            throw new HttpException(422, 'Username already exists');
        }
    }

    /**
     * Add the groups to the given contact
     *
     * @param int $contactId
     * @param string[] $groups
     *
     * @return void
     */
    private function addGroups(int $contactId, array $groups): void
    {
        foreach ($groups as $groupIdentifier) {
            $groupId = $this->getGroupId($groupIdentifier);

            Database::get()->insert('contactgroup_member', [
                'contact_id'        => $contactId,
                'contactgroup_id'   => $groupId
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
                'address'       => $address
            ]);
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
        Database::get()->delete('contactgroup_member', ['contact_id = ?' => $id]);
        Database::get()->delete('contact_address', ['contact_id = ?' => $id]);
        Database::get()->delete('contact', ['id = ?' => $id]);
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
        $data = $this->getRequest()->getPost();
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
                $this->httpBadRequest(sprintf(
                    $msgPrefix . 'undefined address type %s given',
                    implode(', ', array_diff($addressTypes, $types))
                ));
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
