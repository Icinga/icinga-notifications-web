<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\DefaultError422Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\SuccessDataResponse;
use OpenApi\Attributes\Get;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1GetPlural extends Get
{
    public function __construct(
        ?string $entityName = null,
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
            description: $description,
            summary: $summary,
            tags: $tags,
            parameters: array_merge([], $parameters ?? []),
            responses: array_merge([
                new SuccessDataResponse(entityName: $entityName, multipleResults: true),
                new ErrorResponse(
                    response: 400,
                    examples: [
                        new ResponseExample('NoIdentifierWithFilter'),
                    ]
                ),
                new ErrorResponse(
                    response: 422,
                    examples: [
                        new ResponseExample('PayloadIdInvalidUUID'),
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
