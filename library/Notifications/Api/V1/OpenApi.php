<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\PsrLogger;
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
    /**
     * Generate OpenAPI documentation for the Notifications API
     *
     * @return array
     * @throws ProgrammingError
     */
    public function get(): array
    {
        $files = $this->getFilesIncludingDocs();

        try {
            $openapi = (new Generator(new PsrLogger()))
                ->setVersion(\OpenApi\Annotations\OpenApi::VERSION_3_1_0)
                ->generate($files);
        } catch (\RuntimeException $e) {
            $errorBody = json_encode([
                'error' => 'Failed to generate OpenAPI documentation',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

            return $this->createArrayOfResponseData(500, $errorBody);
        }

        $body = $openapi->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

        return $this->createArrayOfResponseData(body: $body);
    }
}
