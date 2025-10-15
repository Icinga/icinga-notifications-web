<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Error404Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\SuccessDataResponse;
use OpenApi\Attributes\Get;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1Get extends Get
{
    public function __construct(
        string $entityName,
        ?string $path = null,
        ?string $description = null,
        ?string $summary = null,
        ?array $tags = null,
        ?array $parameters = null,
        ?array $responses = null,
    ) {
        parent::__construct(
            path: $path,
            operationId: 'get' . $entityName,
            description: $description,
            summary: $summary,
            tags: $tags,
            parameters: array_merge([], $parameters ?? []),
            responses: array_merge([
                new SuccessDataResponse(entityName: $entityName, multipleResults: false),
                new ErrorResponse(
                    response: 400,
                    examples: [
                        new ResponseExample('InvalidIdentifier'),
                        new ResponseExample('NoIdentifierWithFilter'),
                    ]
                ),
                new Error404Response($entityName),
            ], $responses ?? []),
        );
    }
}
