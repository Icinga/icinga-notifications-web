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
        ),
        new OA\Property(
            property: 'message',
            description: 'Detailed error message',
            type: 'string',
        )
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SuccessResponse',
    description: 'Success response format',
    properties: [
        new OA\Property(
            property: 'status',
            description: 'Status of the response',
            type: 'string',
        ),
        new OA\Property(
            property: 'message',
            description: 'Detailed success message',
            type: 'string',
        )
    ],
    type: 'object',
)]
#[OA\Components(
    examples: [
        new OA\Examples(
            example: 'IDParameterInvalidUUID',
            summary: 'Invalid UUID format',
            value: [
                'status'  => 'error',
                'message' => 'Provided id-parameter is not a valid UUID',
            ],
        ),
        new OA\Examples(
            example: 'IdentifierNotFound',
            summary: 'Identifier not found',
            value: ['status' => 'error', 'message' => 'Identifier not found']
        ),
        new OA\Examples(
            example: 'InvalidIdentifier',
            summary: 'Identifier is not valid',
            value: ['status' => 'error', 'message' => 'Identifier is not valid']
        ),
        new OA\Examples(
            example: 'MissingRequiredField',
            summary: 'Missing required field',
            value: [
                'status'  => 'error',
                'message' => 'Missing required field in request body: X',
            ],
        ),
        new OA\Examples(
            example: 'ContentTypeNotSupported',
            summary: 'Content type not supported',
            value: [
                'status'  => 'error',
                'message' => 'Content type is missing or not supported, please use application/json',
            ],
        ),
        new OA\Examples(
            example: 'InvalidRequestBody',
            summary: 'Invalid request body',
            value: [
                'status'  => 'error',
                'message' => 'Request body is not valid JSON',
            ],
        )
    ]
)]
#[OA\Schema(
    schema: 'UUID',
    description: 'An UUID representing',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
)]
class OpenApi extends ApiV1
{
    public const OPENAPI_PATH = __DIR__ . '/docs/openapi.json';
    /**
     * Generate OpenAPI documentation for the Notifications API
     *
     * @return array
     * @throws ProgrammingError
     */
    public function get(): array
    {
        // TODO: Create the documentation during CI and not on request
        if (file_exists(self::OPENAPI_PATH)) {
            $oad = file_get_contents(self::OPENAPI_PATH);
        } else {
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

            $oad = $openapi->toJson(
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT
            );

            if (! is_dir(dirname(self::OPENAPI_PATH))) {
                mkdir(dirname(self::OPENAPI_PATH), 0755, true);
            }

            file_put_contents(self::OPENAPI_PATH, $oad);
        }

        return $this->createArrayOfResponseData(body: $oad);
    }
}
