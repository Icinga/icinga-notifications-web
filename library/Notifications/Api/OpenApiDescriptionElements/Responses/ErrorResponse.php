<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses;

use OpenApi\Attributes\Response;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorResponse',
    description: 'Error response format',
    properties: [
        new OA\Property(
            property: 'message',
            description: 'Detailed error message',
            type: 'string',
        )
    ],
    type: 'object',
)]

class ErrorResponse extends Response
{
    public const ERROR_RESPONSES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
    ];

    public function __construct(
        object|string|null $ref = null,
        int $response = 400,
        ?array $examples = null,
        ?array $headers = null,
        ?array $links = null,
    ) {
        if (isset(self::ERROR_RESPONSES[$response])) {
            $description = self::ERROR_RESPONSES[$response];
        } else {
            throw new \InvalidArgumentException('Unexpected response type');
        }

        parent::__construct(
            ref: $ref,
            response: $response,
            description: $description,
            headers: $headers,
            content: new OA\JsonContent(
                examples: $examples,
                ref: '#/components/schemas/ErrorResponse',
            ),
            links: $links,
        );
    }
}
