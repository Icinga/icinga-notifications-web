<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\PsrLogger;
use Icinga\Util\Environment;
use Icinga\Web\Response;
use OpenApi\Generator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Url',
    description: 'A URL used in the API',
    type: 'string',
    maxLength: 2048,
    example: 'example.com',
)]
#[OA\Schema(
    schema: 'Port',
    description: 'A port number',
    type: 'integer',
    format: 'int32',
    maximum: 65535,
    minimum: 1,
)]
#[OA\Schema(
    schema: 'Email',
    description: 'An email address',
    type: 'string',
    format: 'email',
    maxLength: 320,
)]
#[OA\Schema(
    schema: 'ErrorStatus',
    description: 'status',
    type: 'string',
    example: 'error',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    description: 'Error response format',
    properties: [
        new OA\Property(
            property: 'status',
            description: 'Status of the response',
            type: 'string',
            example: 'error'
        ),
        new OA\Property(
            property: 'message',
            description: 'Detailed error message',
            type: 'string',
            example: 'An error occurred while processing your request.'
        )
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UUID',
    title: 'UUID',
    description: 'An UUID representing',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
)]
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
