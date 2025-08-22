<?php

namespace Icinga\Module\Notifications\Api;

use Icinga\Application\Icinga;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Environment;
use Icinga\Web\Response;
use ipl\Sql\Connection;
use OpenApi\Attributes as OA;



abstract class ApiCore
{
    /**
     * The endpoint for OpenAPI documentation
     * This is used to serve the OpenAPI specification.
     *
     * @var string
     */
    public const OPENAPI_ENDPOINT = 'openapi';

    /**
     * The results of the API call, which will be returned as JSON.
     *
     * @var array
     */
    protected array $results = [];
    /**
     * The HTTP response code to be returned.
     *
     * @var int
     */
    protected int $responseCode = 200;
    /**
     * The database connection used for API operations.
     *
     * @var Connection
     */
    protected Connection $db;
    /**
     * The version of the API being used.
     *
     * @var string
     */
    protected string $version;

    public function __construct(
        /**
         * The HTTP response object used to send responses back to the client.
         *
         * @var Response
         */
        protected Response $response,
    )
    {
        $this->db = Database::get();
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
    public function httpBadRequest(string $message, mixed ...$arg) :never
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
     * Get the files including the ApiCore.php file and any other files matching the given filter.
     *
     * @param string $fileFilter
     * @return array
     * @throws ProgrammingError
     */
    protected function getFilesIncludingDocs(string $fileFilter = '*'): array
    {
        $apiCoreDir = __DIR__ . '/ApiCore.php';
        // check if the extended object of this class has a attribute 'moduleName'
        $moduleName = property_exists($this, 'moduleName') ? $this->moduleName : 'default;';
        if ($moduleName === 'default' || $moduleName === '') {
            $dir = Icinga::app()->getLibraryDir('Icinga/Application/Api/' . ucfirst($this->version) . '/');
        } else {
            $dir = Icinga::app()->getModuleManager()->getModuleDir($moduleName)
                . '/library/' . ucfirst($moduleName) . '/Api/' . strtoupper($this->version) . '/';
        }

        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory $dir does not exist");
        }
        if (!is_readable($dir)) {
            throw new \RuntimeException("Directory $dir is not readable");
        }

        $files = glob($dir . $fileFilter, GLOB_NOSORT | GLOB_BRACE | GLOB_MARK);
        array_unshift($files, $apiCoreDir);
        if ($files === false) {
            throw new \RuntimeException("Failed to read files from directory: $dir");
        }

        return $files;
    }

    protected function sendJsonResponse(callable $printFunc): void
    {
        ob_end_clean();
        Environment::raiseExecutionTime();

        $this->getResponse()
        ->setHeader('Content-Type', 'application/json')
        ->setHeader('Cache-Control', 'no-store')
        ->sendResponse();

        $printFunc();
    }

}
