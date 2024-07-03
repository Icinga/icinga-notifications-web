<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

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

class ApiV1ChannelsController extends CompatController
{
    public function indexAction(): void
    {
        $this->assertPermission('notifications/api/v1');

        $request = $this->getRequest();
        if (! $request->isApiRequest()) {
            $this->httpBadRequest('No API request');
        }

        $method = $request->getMethod();
        if ($method !== 'GET') {
            $this->httpBadRequest('Only GET method supported');
        }

        $db = Database::get();

        /** @var ?string $identifier */
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
                        if (! in_array($column, ['id', 'name', 'type'])) {
                            $this->httpBadRequest(sprintf(
                                'Invalid filter column %s given, only id, name and type are allowed',
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

        $stmt = (new Select())
            ->distinct()
            ->from('channel ch')
            ->columns([
                'channel_id'    => 'ch.id',
                'id'            => 'ch.external_uuid',
                'name',
                'type',
                'config'
            ]);

        if ($identifier !== null) {
            $stmt->where(['external_uuid = ?' => $identifier]);

            /** @var stdClass|false $result */
            $result = $db->fetchOne($stmt);

            if ($result === false) {
                $this->httpNotFound('Channel not found');
            }

            unset($result->channel_id);

            $this->getResponse()
                ->setHttpResponseCode(200)
                ->json()
                ->setSuccessData((array) $result)
                ->sendResponse();
        } else {
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
                    if ($i > 0 || $offset !== 0) {
                        echo ",\n";
                    }

                    unset($row->channel_id);

                    echo Json::sanitize($row);
                }

                $offset += 500;
                $res = $db->select($stmt->offset($offset));
            } while ($res->rowCount());

            echo ']';
        }

        exit;
    }
}
