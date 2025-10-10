<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Email',
    description: 'An email address',
    type: 'string',
    format: 'email',
    maxLength: 320,
)]
#[OA\Schema(
    schema: 'Port',
    description: 'A port number',
    type: 'string',
    maxLength: 5,
    minLength: 1,
)]
#[OA\Schema(
    schema: 'Url',
    description: 'A URL used in the API',
    type: 'string',
    maxLength: 2048,
    example: 'example.com',
)]
class DefaultSchemas
{
}
