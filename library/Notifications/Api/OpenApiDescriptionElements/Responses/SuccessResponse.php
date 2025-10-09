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
        ?array $examples = null,
        ?array $headers = null,
        ?array $links = null,
    ) {
        if (! isset(self::SUCCESS_RESPONSES[$response])) {
            throw new \InvalidArgumentException('Unexpected response type');
        }

        $content = $response !== 204
            ? new OA\JsonContent(
                examples: $examples,
                ref: '#/components/schemas/SuccessResponse',
            )
            : null;

        parent::__construct(
            response: $response,
            description: $description,
            headers: $headers,
            content: $content,
            links: $links
        );
    }
}
