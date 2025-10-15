<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters\PathParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Error404Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\SuccessResponse;
use OpenApi\Attributes\Delete;
use OpenApi\Attributes\ExternalDocumentation;
use OpenApi\Attributes\RequestBody;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class OadV1Delete extends Delete
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
            operationId: 'delete' . $entityName,
            description: $description,
            summary: $summary,
            tags: $tags,
            parameters: array_merge([
                new PathParameter(
                    name: 'identifier',
                    description: 'The UUID of the ' . $entityName . ' to delete',
                    identifierSchema: $entityName . 'UUID'
                ),
            ], $parameters ?? []),
            responses: array_merge([
                new SuccessResponse(
                    response: 204,
                    description: 'No Content - The ' . $entityName . ' has been deleted successfully',
                ),
                new ErrorResponse(response: 400, examples: [new ResponseExample('InvalidIdentifier'),]),
                new Error404Response($entityName),
            ], $responses ?? []),
        );
    }
}
