<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Error404Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\SuccessResponse;
use OpenApi\Attributes\Put;
use OpenApi\Attributes\RequestBody;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1Put extends Put
{
    public function __construct(
        string $entityName,
        ?string $path = null,
        ?string $description = null,
        ?string $summary = null,
        ?array $requiredFields = null,
        ?RequestBody $requestBody = null,
        ?array $tags = null,
        ?array $parameters = null,
        ?array $responses = null,
        ?array $examples400 = null,
        ?array $examples422 = null,
    ) {
        $missingRequestBodyFieldsMessage = 'Invalid request body: ';
        if (! empty($requiredFields)) {
            if (count($requiredFields) == 1) {
                $requiredFieldsStr = $requiredFields[0];
            } elseif (count($requiredFields) == 2) {
                $requiredFieldsStr = $requiredFields[0] . ' and ' . $requiredFields[1];
            } else {
                $last = array_pop($requiredFields);
                $requiredFieldsStr = implode(', ', $requiredFields) . ' and ' . $last;
            }
            $missingRequestBodyFieldsMessage .= sprintf(
                'the fields %s must be present and of type string',
                $requiredFieldsStr
            );
        }

        parent::__construct(
            path: $path,
            description: $description,
            summary: $summary,
            requestBody: $requestBody,
            tags: $tags,
            parameters: $parameters,
            responses: array_merge(
                [
                    new SuccessResponse(
                        response: 201,
                        description: $entityName . ' created successfully',
                        examples: [
                            new OA\Examples(
                                example: $entityName . 'Created',
                                summary: $entityName . ' created successfully',
                                value: [
                                    'message' => $entityName . ' created successfully',
                                ]
                            ),
                        ],
                        headers: [
                            'Location' => sprintf(
                                'notifications/api/v1/%s/{identifier}',
                                strtolower($entityName) . 's'
                            )
                        ]
                    ),
                    new SuccessResponse(
                        response: 204,
                        description: $entityName . ' updated successfully',
                    ),
                    new ErrorResponse(
                        response: 400,
                        examples: array_merge([
                            new ResponseExample('InvalidRequestBodyFormat'),
                            new ResponseExample('UnexpectedQueryParameter'),
                        ], $examples400 ?? [])
                    ),
                    new Error404Response($entityName),
                    new ErrorResponse(
                        response: 415,
                        examples: [
                            new ResponseExample('InvalidContentType'),
                        ]
                    ),
                    new ErrorResponse(
                        response: 422,
                        examples: array_merge(
                            [
                                new OA\Examples(
                                    example: $entityName . ' AlreadyExists',
                                    summary: $entityName . ' already exists',
                                    value: ['message' => $entityName . ' already exists'],
                                ),
                                new ResponseExample('InvalidRequestBodyId'),
                                new ResponseExample('IdentifierMismatch')
                            ],
                            empty($requiredFields)
                                ? []
                                : [
                                new OA\Examples(
                                    example: 'MissingRequiredRequestBodyField',
                                    summary: 'Missing required request body field',
                                    value: ['message' => $missingRequestBodyFieldsMessage],
                                )
                            ],
                            $examples422 ?? []
                        )
                    ),

                ],
                $responses ?? []
            ),
        );
    }
}
