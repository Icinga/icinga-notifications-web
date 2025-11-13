<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Example\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\SuccessDataResponse;
use OpenApi\Attributes\Get;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1GetPlural extends Get
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
            operationId: 'list' . $entityName,
            description: $description,
            summary: $summary,
            tags: $tags,
            parameters: array_merge([], $parameters ?? []),
            responses: array_merge([
                new SuccessDataResponse(entityName: $entityName),
                new ErrorResponse(
                    response: 400,
                    examples: [
                        new ResponseExample('NoIdentifierWithFilter'),
                    ]
                ),
                new ErrorResponse(
                    response: 422,
                    examples: [
                        new ResponseExample('InvalidRequestBodyId'),
                        new ResponseExample('InvalidFilterParameter'),
                    ]
                ),
            ], $responses ?? []),
        );
    }
}
