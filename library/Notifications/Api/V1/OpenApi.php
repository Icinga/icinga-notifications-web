<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\PsrLogger;
use Icinga\Util\Environment;
use Icinga\Web\Response;
use OpenApi\Generator;

class OpenApi extends ApiV1
{
    public function __construct(
        /**
         * The name of the module for which the OpenAPI documentation is generated.
         * This is used to identify the module in the API documentation.
         *
         * @var string
         */
        protected string $moduleName,
        Response $response
    ) {
        parent::__construct($response);
    }

    /**
     * Generate OpenAPI documentation for the Notifications API
     *
     * @return void
     * @throws ProgrammingError
     */
    public function get(): void
    {
        $files = $this->getFilesIncludingDocs();

        try {
            $openapi = (new Generator(new PsrLogger()))
                ->setVersion(\OpenApi\Annotations\OpenApi::VERSION_3_1_0)
                ->generate($files);
        } catch (\RuntimeException $e) {
            $this->getResponse()->setHeader('Status', '500 Internal Server Error');
            echo json_encode([
                'error' => 'Failed to generate OpenAPI documentation',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
            return;
        }

        ob_end_clean();
        Environment::raiseExecutionTime();

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json')
            ->sendResponse();
        echo $openapi->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        exit;
    }
}
