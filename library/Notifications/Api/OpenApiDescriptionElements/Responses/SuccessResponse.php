<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses;

use OpenApi\Attributes\Response;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SuccessResponse',
    description: 'Success response format',
    properties: [
        new OA\Property(
            property: 'message',
            description: 'Detailed success message',
            type: 'string',
        )
    ],
    type: 'object',
)]
class SuccessResponse extends Response
{
    public const SUCCESS_RESPONSES = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
    ];

    public function __construct(
        int|string|null $response = null,
        ?string $description = null,
        ?array $headers = null,
    ) {
        if (! isset(self::SUCCESS_RESPONSES[$response])) {
            throw new \InvalidArgumentException('Unexpected response type');
        }
        parent::__construct(
            response: $response,
            description: $description,
            headers: $headers,
        );
    }
}
