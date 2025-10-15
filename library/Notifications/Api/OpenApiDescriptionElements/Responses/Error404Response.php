<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses;

use OpenApi\Attributes\Attachable;
use OpenApi\Attributes\Examples;
use OpenApi\Attributes\JsonContent;
use OpenApi\Attributes\MediaType;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\XmlContent;

class Error404Response extends Response
{
    public function __construct(string $endpointName = null)
    {
        parent::__construct(
            response: 404,
            description: $endpointName . ' Not Found',
            content: new JsonContent(
                examples: [
                    new Examples(
                        example: 'ResourceNotFound',
                        summary: 'Resource not found',
                        value: ['message' => $endpointName . ' not found'],
                    )
                ],
                ref: '#/components/schemas/ErrorResponse'
            )
        );
    }
}
