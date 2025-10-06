<?php

namespace Icinga\Module\Notifications\Api\V1;

use Exception;
use Generator;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Json\JsonDecodeException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\ApiCore;
use Icinga\Module\Notifications\Api\Elements\HttpMethod;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Compat\FilterProcessor;
use ipl\Sql\Select;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Filter\QueryString;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * Base class for API version 1.
 *
 * This class provides common functionality for API endpoints in version 1 of the Icinga Notifications API.
 */
#[OA\OpenApi(
    info: new OA\Info(
        version: '1.0.0',
        description: 'API for managing notification channels',
        title: 'Icinga Notifications API',
    ),
    servers: [
        new OA\Server(
            url: 'http://localhost/icingaweb2/notifications/api/v1',
            description: 'Local server',
        )
    ],
    security: [
        ['BasicAuth' => []],
    ],
)]
#[OA\Tag(
    name: 'Contacts',
    description: 'Operations related to notification Contacts'
)]
#[OA\Tag(
    name: 'Contactgroups',
    description: 'Operations related to notification contactgroups'
)]
#[OA\Tag(
    name: 'Channels',
    description: 'Operations related to notification channels'
)]
#[OA\SecurityScheme(
    securityScheme: 'BasicAuth',
    type: 'http',
    description: 'Basic authentication for API access',
    scheme: 'basic',
)]
abstract class ApiV1 extends ApiCore
{
    /**
     * This constant defines the version of the API.
     *
     * @var string
     */
    public const VERSION = 'v1';

    /**
     * @throws HttpBadRequestException If the request is not valid.
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $identifier = $request->getAttribute('identifier');
        $queryFilter = $request->getUri()->getQuery();

        return match ($request->getAttribute('httpMethod')) {
            HttpMethod::PUT    => $this->put($identifier, $this->getValidRequestBody($request)),
            HttpMethod::POST   => $this->post($identifier, $this->getValidRequestBody($request)),
            HttpMethod::GET    => $this->get($identifier, $queryFilter),
            HttpMethod::DELETE => $this->delete($identifier),
        };
    }

    /**
     * @throws HttpBadRequestException If the request is not valid.
     */
    protected function assertValidRequest(ServerRequestInterface $request): void
    {
        $httpMethod = $request->getAttribute('httpMethod');
        $identifier = $request->getAttribute('identifier');
        $queryFilter = $request->getUri()->getQuery();

        if ($httpMethod !== HttpMethod::GET && ! empty($queryFilter)) {
            throw new HttpBadRequestException(
                'Unexpected query parameter: Filter is only allowed for GET requests'
            );
        }

        if ($httpMethod === HttpMethod::GET && ! empty($identifier) && ! empty($queryFilter)) {
            throw new HttpBadRequestException(sprintf(
                'Invalid request: %s with identifier and query parameters, it\'s not allowed to use both together.',
                $httpMethod->uppercase()
            ));
        }

        if (
            ! in_array($httpMethod, [HttpMethod::PUT, HttpMethod::POST])
            && (! empty($request->getBody()->getSize()) || ! empty($request->getParsedBody()))
        ) {
            throw new HttpBadRequestException('Invalid request: Body is only allowed for POST and PUT requests');
        }

        if (in_array($httpMethod, [HttpMethod::PUT, HttpMethod::DELETE]) && empty($identifier)) {
            throw new HttpBadRequestException("Invalid request: Identifier is required");
        }

        if (! empty($identifier) && ! Uuid::isValid($identifier)) {
            throw new HttpBadRequestException('The given identifier is not a valid UUID');
        }
    }

    /**
     * Override this method to modify the row before it is returned in the response.
     *
     * @param stdClass $row
     * @return void
     */
    public function prepareRow(stdClass $row): void
    {
    }

    /**
     * Create a filter from the filter string.
     *
     * @param string $queryFilter
     * @param array $allowedColumns
     * @param string $idColumnName
     *
     * @return array|bool Returns an array of filter rules or false if no filter string is provided.
     *
     * @throws HttpBadRequestException If the filter string cannot be parsed.
     */
    protected function assembleFilter(string $queryFilter, array $allowedColumns, string $idColumnName): array|bool
    {
        if (empty($queryFilter)) {
            return false;
        }

        try {
            $filterRule = QueryString::fromString($queryFilter)
                ->on(
                    QueryString::ON_CONDITION,
                    function (Condition $condition) use ($allowedColumns, $idColumnName) {
                        $column = $condition->getColumn();
                        if (! in_array($column, $allowedColumns)) {
                            throw new HttpBadRequestException(
                                sprintf(
                                    'Invalid request parameter: Filter column %s given, only %s are allowed',
                                    $column,
                                    preg_replace('/,([^,]*)$/', ' and$1', implode(', ', $allowedColumns))
                                )
                            );
                        }

                        if ($column === 'id') {
                            if (! Uuid::isValid($condition->getValue())) {
                                throw new HttpBadRequestException('The given filter id is not a valid UUID');
                            }

                            $condition->setColumn($idColumnName);
                        }
                    }
                )->parse();

            return FilterProcessor::assembleFilter($filterRule);
        } catch (Exception $e) {
            throw new HttpBadRequestException($e->getMessage());
        }
    }

    /**
     * Validate that the request has a JSON content type and return the parsed JSON content.
     *
     * @param ServerRequestInterface $request The request object to validate.
     *
     * @return array The validated JSON content as an associative array.
     *
     * @throws HttpBadRequestException If the content type is not application/json.
     */
    private function getValidRequestBody(ServerRequestInterface $request): array
    {
        if ($request->getHeaderLine('Content-Type') !== 'application/json') {
            throw new HttpBadRequestException('Invalid request header: Content-Type must be application/json');
        }

        if (! empty($parsedBody = $request->getParsedBody()) && is_array($parsedBody)) {
            return $parsedBody;
        }

        $msgPrefix = 'Invalid request body: ';
        $body = $request->getBody()->getContents();

        if (empty($body)) {
            throw new HttpBadRequestException($msgPrefix . 'given content is empty');
        }

        try {
            $validBody = Json::decode($body, true);
        } catch (JsonDecodeException) {
            throw new HttpBadRequestException($msgPrefix . 'given content is not a valid JSON');
        }

        return $validBody;
    }

    /**
     * Generates a streamable response for large datasets.
     *
     * Enables efficient delivery of data by yielding results in batches.
     *
     * @param Select $stmt The SQL select statement to execute.
     * @param int $batchSize The number of rows to fetch in each batch (default is 500).
     *
     * @return Generator Yields JSON-encoded strings representing the content.
     *
     * @throws JsonEncodeException
     */
    protected function createContentGenerator(
        Select $stmt,
        int $batchSize = 500
    ): Generator {
        $stmt->limit($batchSize);
        $offset = 0;

        if ($stmt->getOrderBy() === null) {
            $stmt->orderBy('id');
        }

        yield '{"data":[';
        $res = Database::get()->select($stmt->offset($offset));
        do {
            /** @var stdClass $row */
            foreach ($res as $i => $row) {
                $this->prepareRow($row);

                if ($i > 0 || $offset !== 0) {
                    yield ",";
                }

                yield Json::sanitize($row);
            }

            $offset += $batchSize;
            $res = Database::get()->select($stmt->offset($offset));
        } while ($res->rowCount());

        yield ']}';
    }
}
