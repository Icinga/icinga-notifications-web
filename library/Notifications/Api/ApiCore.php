<?php

namespace Icinga\Module\Notifications\Api;

use Generator;
use GuzzleHttp\Psr7\HttpFactory;
use Icinga\Application\Icinga;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Exception\ProgrammingError;
use Icinga\Util\Json;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

use function Icinga\Module\Kubernetes\yield_iterable;

abstract class ApiCore implements RequestHandlerInterface
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
     * The version of the API being used.
     *
     * @var string
     */
    private string $version;
    /**
     * The database connection used for API operations.
     *
     * @var Connection
     */
    private Connection $db;
    /**
     * The response object used to send back the API response.
     *
     * @var ResponseInterface
     */
    private ResponseInterface $response;

    public function __construct()
    {
        $this->setResponse((new HttpFactory())->createResponse());
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
     * @return void
     */
    protected function setDB(Connection $db): void
    {
        $this->db = $db;
    }
    /**
     * Get the Response object
     *
     * This method returns the response object that is used to send back the API response.
     *
     * @return ResponseInterface
     */
    protected function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Set the Response object
     *
     * This method sets the response object that is used to send back the API response.
     *
     * @param ResponseInterface $response
     * @return void
     */
    protected function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * Get the API version
     *
     * This method returns the version of the API being used.
     *
     * @return string
     */
    protected function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Set the API version
     *
     * This method sets the version of the API being used.
     * It can only be set once and will not overwrite an existing version.
     *
     * @param string $version
     * @return void
     */
    protected function setVersion(string $version): void
    {
        if (! isset($this->version)) {
            $this->version = $version;
        }
    }

    /**
     * Get the files including the ApiCore.php file and any other files matching the given filter.
     *
     * @param string $fileFilter
     * @return array
     * @throws ProgrammingError
     */
    protected function getFilesIncludingDocs(string $fileFilter = '*'): array
    {
        $apiCoreDir = __DIR__ . '/ApiCore.php';
        // TODO: find a way to get the module name from the request or class context
//        $moduleName = $this->getRequest()->getModuleName() ?: 'default;';
        $moduleName = 'notifications';
        if ($moduleName === 'default' || $moduleName === '') {
            $dir = Icinga::app()->getLibraryDir('Icinga/Application/Api/' . ucfirst($this->getVersion()) . '/');
        } else {
            $dir = Icinga::app()->getModuleManager()->getModuleDir($moduleName)
                . '/library/' . ucfirst($moduleName) . '/Api/' . strtoupper($this->getVersion()) . '/';
        }

        $dir = rtrim($dir, '/') . '/';
        if (! is_dir($dir)) {
            throw new \RuntimeException("Directory $dir does not exist");
        }
        if (! is_readable($dir)) {
            throw new \RuntimeException("Directory $dir is not readable");
        }

        $files = glob($dir . $fileFilter, GLOB_NOSORT | GLOB_BRACE | GLOB_MARK);
        array_unshift($files, $apiCoreDir);
        if ($files === false) {
            throw new \RuntimeException("Failed to read files from directory: $dir");
        }

        return $files;
    }

    /**
     * Immediately respond w/ HTTP 400
     *
     * @param string $message Exception message or exception format string
     * @param mixed ...$arg Format string argument
     *
     * @return never
     *
     * @throws  HttpBadRequestException
     */
    public function httpBadRequest(string $message, mixed ...$arg): never
    {
        throw HttpBadRequestException::create(func_get_args());
    }


    /**
     * Immediately respond w/ HTTP 404
     *
     * @param string $message Exception message or exception format string
     * @param mixed ...$arg Format string argument
     *
     * @return never
     *
     * @throws  HttpNotFoundException
     */
    public function httpNotFound(string $message, mixed ...$arg): never
    {
        throw HttpNotFoundException::create(func_get_args());
    }

    /**
     * Immediately respond w/ HTTP 405
     * This method throws an HttpException with a 405 status code (Method Not Allowed).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public function httpMethodNotAllowed(string $message, mixed ...$arg): never
    {
        throw new HttpException(405, ...func_get_args());
    }

    /**
     *  Immediately respond w/ HTTP 409
     * This method throws an HttpException with a 409 status code (Conflict).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public function httpConflict(string $message, mixed ...$arg): never
    {
        throw new HttpException(409, ...func_get_args());
    }

    /**
     * Immediately respond w/ HTTP 415
     * This method throws an HttpException with a 415 status code (Unsupported Media Type).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public function httpUnsupportedMediaType(string $message, mixed ...$arg): never
    {
        throw new HttpException(415, ...func_get_args());
    }
    /**
     * Immediately respond w/ HTTP 422
     * This method throws an HttpException with a 422 status code (Unprocessable Entity).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public function httpUnprocessableEntity(string $message, mixed ...$arg): never
    {
        throw new HttpException(422, ...func_get_args());
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

        yield '{"contents":[';
         $res = $db->select($stmt->offset($offset));
        do {
            /** @var stdClass $row */
            foreach ($res as $i => $row) {
                $enricher($row);

                if ($i > 0 || $offset !== 0) {
                    yield ",\n";
                }

                unset($row->contact_id);

                yield Json::sanitize($row);
            }

            $offset += $batchSize;
            $res = $db->select($stmt->offset($offset));
        } while ($res->rowCount());
        yield ']}';
    }
}
