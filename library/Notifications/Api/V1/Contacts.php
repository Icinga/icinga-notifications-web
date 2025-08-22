<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Select;
use ipl\Stdlib\Filter\Condition;
use Ramsey\Uuid\Uuid;
use stdClass;

class Contacts extends ApiV1
{
    /**
     * Get a contact by UUID.
     *
     * @return void
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    public function get(): void
    {
        $stmt = $this->createSelectStmt();

        $stmt->where(['co.external_uuid = ?' => $this->identifier]);

        /** @var stdClass|false $result */
        $result = $this->db->fetchOne($stmt);

        if (empty($result)) {
            $this->httpNotFound('Contact not found');
        }

        $result->groups = ContactGroups::fetchGroupIdentifiers($result->contact_id);
        $result->addresses = $this->fetchContactAddresses($result->contact_id);

        unset($result->contact_id);
        $this->results[] = $result;

        $this->sendJsonResponse(
        /** @throws JsonEncodeException */
        function () {
            echo Json::sanitize($this->results[0]);
        });

    }

    /**
     * List contacts or get specific contacts by UUID or filter parameters.
     *
     * @throws JsonEncodeException
     * @throws HttpBadRequestException
     */
    public function getAny(): void
    {
        $stmt = $this->createSelectStmt();
        $filter = $this->createFilterFromFilterStr(
            function (Condition $condition) {
                $column = $condition->getColumn();
                if (!in_array($column, ['id', 'full_name', 'username'])) {
                    $this->httpBadRequest(
                        sprintf(
                            'Invalid filter column %s given, only id, full_name and username are allowed',
                            $column
                        )
                    );
                }

                if ($column === 'id') {
                    if (!Uuid::isValid($condition->getValue())) {
                        $this->httpBadRequest('The given filter id is not a valid UUID');
                    }

                    $condition->setColumn('co.external_uuid');
                }
            }
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        $this->sendJsonResponse(function () use ($stmt) {
            $stmt->limit(500);
            $offset = 0;

            echo '[';

            $res = $this->db->select($stmt->offset($offset));
            do {
                /** @var stdClass $row */
                foreach ($res as $i => $row) {
                    $row->groups = ContactGroups::fetchGroupIdentifiers($row->contact_id);
                    $row->addresses = Contacts::fetchContactAddresses($row->contact_id);

                    if ($i > 0 || $offset !== 0) {
                        echo ",\n";
                    }

                    unset($row->contact_id);

                    echo Json::sanitize($row);
                }

                $offset += 500;
                $res = $this->db->select($stmt->offset($offset));
            } while ($res->rowCount());

            echo ']';
        });
    }

    /**
     * Create a base Select query for contacts
     *
     * @return Select
     */
    private function createSelectStmt(): Select
    {
        return (new Select())
            ->distinct()
            ->from('contact co')
            ->columns([
                'contact_id' => 'co.id',
                'id' => 'co.external_uuid',
                'full_name',
                'username',
                'default_channel' => 'ch.external_uuid',
            ])
            ->joinLeft('contact_address ca', 'ca.contact_id = co.id')
            ->joinLeft('channel ch', 'ch.id = co.default_channel_id')
            ->where(['co.deleted = ?' => 'n']);
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


}
