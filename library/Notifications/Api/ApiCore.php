<?php

namespace Icinga\Module\Notifications\Api;

use Generator;
use GuzzleHttp\Psr7\Response;
use Icinga\Application\Icinga;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Exception\ProgrammingError;
use Icinga\Util\Json;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use Psr\Http\Message\ResponseInterface;
use stdClass;

abstract class ApiCore
{
    /**
     * HTTP GET method
     * This constant represents the HTTP GET method.
     * @var string
     */
    public const GET = 'GET';
    /**
     * HTTP POST method
     * This constant represents the HTTP POST method.
     * @var string
     */
    public const POST = 'POST';
    /**
     * HTTP PUT method
     * This constant represents the HTTP PUT method.
     * @var string
     */
    public const PUT = 'PUT';
    /**
     * HTTP DELETE method
     * This constant represents the HTTP DELETE method.
     * @var string
     */
    public const DELETE = 'DELETE';
    /**
     * The endpoint for OpenAPI documentation
     * This is used to serve the OpenAPI specification.
     *
     * @var string
     */
    public const OPENAPI_ENDPOINT = 'openapi';

    /**
     * The database connection used for API operations.
     *
     * @var Connection
     */
    private Connection $db;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize the API core.
     *
     * This method is called in the constructor and should be implemented by subclasses
     * to perform any necessary initialization tasks.
     * E.g., establishing a database connection and adding Response data.
     *
     * @return void
     */
    abstract protected function init(): void;

    /**
     * Get the database connection
     *
     * This method returns the database connection that is used for API operations.
     *
     * @return Connection
     */
    protected function getDB(): Connection
    {
        return $this->db;
    }

    /**
     * Set the database connection
     *
     * This method sets the database connection that will be used for API operations.
     *
     * @param Connection $db
     * @return static
     */
    protected function setDB(Connection $db): static
    {
        $this->db = $db;

        return $this;
    }

    /**
     * Create a content generator for streaming JSON responses.
     *
     * This method creates a generator that yields JSON-encoded content
     * in batches, allowing for efficient streaming of large datasets.
     *
     * @param Connection $db The database connection to use for querying.
     * @param Select $stmt The SQL select statement to execute.
     * @param callable $enricher A function to enrich each row of data.
     * @param int $batchSize The number of rows to fetch in each batch (default is 500).
     *
     * @return Generator Yields JSON-encoded strings representing the content.
     * @throws JsonEncodeException
     */
    protected function createContentGenerator(
        Connection $db,
        Select $stmt,
        callable $enricher,
        int $batchSize = 500
    ): Generator {
        $stmt->limit($batchSize);
        $offset = 0;

        yield '{"data":[';
         $res = $db->select($stmt->offset($offset));
        do {
            /** @var stdClass $row */
            foreach ($res as $i => $row) {
                $enricher($row);

                if ($i > 0 || $offset !== 0) {
                    yield ",\n";
                }

                yield Json::sanitize($row);
            }

            $offset += $batchSize;
            $res = $db->select($stmt->offset($offset));
        } while ($res->rowCount());
        yield ']}';
    }
}
