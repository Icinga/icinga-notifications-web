<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples;

use OpenApi\Attributes\Examples;
use OpenApi\Attributes as OA;

#[OA\Examples(
    example: 'IdentifierNotFound',
    summary: 'Identifier not found',
    value: ['message' => 'Identifier not found']
)]
#[OA\Examples(
    example: 'NoIdentifierWithFilter',
    summary: 'No identifier with filter',
    value: [
        'message' =>
            "Invalid request: GET with identifier and query parameters, it's not allowed to use both together.",
    ],
)]
#[OA\Examples(
    example: 'PayloadIdInvalidUUID',
    summary: 'Payload Id invalid UUID',
    value: ['message' => 'Provided id-parameter is not a valid UUID'],
)]
class ResponseExample extends Examples
{
    public function __construct(string $name)
    {
        parent::__construct(example: $name, ref: '#/components/examples/' . $name);
    }
}
