<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Example\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\SuccessDataResponse;
use OpenApi\Attributes\Get;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1GetPlural extends Get
{
    public function __construct(
        string $entityName,
        ?string $path = null,
        ?string $description = null,
        ?string $summary = null,
        ?array $tags = null,
        ?array $filter = null,
        ?array $parameters = null,
        ?array $responses = null,
    ) {
        $message = 'Invalid request parameter: Filter column x given, ';
        if (empty($filter)) {
            $message .= 'no filter allowed';
        } else {
            if (count($filter) == 1) {
                $filterStr = $filter[0];
            } elseif (count($filter) == 2) {
                $filterStr = $filter[0] . ' and ' . $filter[1];
            } else {
                $last = array_pop($filter);
                $filterStr = implode(', ', $filter) . ' and ' . $last;
            }
            $message .= sprintf('only %s are allowed', $filterStr);
        }

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
                        new OA\Examples(
                            example: 'InvalidFilterParameter',
                            summary: 'Invalid filter parameter',
                            value: [
                                'message' => $message
                            ]
                        ),
                    ]
                ),
            ], $responses ?? []),
        );
    }
}
