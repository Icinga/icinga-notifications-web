<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples;

use OpenApi\Attributes\Examples;
use OpenApi\Attributes as OA;

#[OA\Examples(
    example: 'IdentifierMismatch',
    summary: 'Identifier mismatch',
    value: ['message' => 'Identifier mismatch'],
)]
#[OA\Examples(
    example: 'IdentifierNotFound',
    summary: 'Identifier not found',
    value: ['message' => 'Identifier not found']
)]
#[OA\Examples(
    example: 'IdentifierPayloadIdMissmatch',
    summary: 'Identifier and payload Id missmatch',
    value: ['message' => 'Identifier mismatch: the Payload id must be different from the URL identifier'],
)]
#[OA\Examples(
    example: 'InvalidContentType',
    summary: 'Invalid content type',
    value: ['message' => 'Invalid request header: Content-Type must be application/json'],
)]
#[OA\Examples(
    example: 'InvalidIdentifier',
    summary: 'Identifier is not valid',
    value: ['message' => 'The given identifier is not a valid UUID']
)]
#[OA\Examples(
    example: 'InvalidRequestBodyFormat',
    summary: 'Invalid request body format',
    value: ['message' => 'Invalid request body: given content is not a valid JSON'],
)]
#[OA\Examples(
    example: 'InvalidRequestBodyId',
    summary: 'Invalid request body id',
    value: ['message' => 'Invalid request body: given id is not a valid UUID'],
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
    example: 'UnexpectedQueryParameter',
    summary: 'Unexpected query parameter',
    value: ['message' => 'Unexpected query parameter: Filter is only allowed for GET requests']
)]
class ResponseExample extends Examples
{
    public function __construct(string $name)
    {
        parent::__construct(example: $name, ref: '#/components/examples/' . $name);
    }
}
