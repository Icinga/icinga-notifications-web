<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Error404Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Example\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\SuccessResponse;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\RequestBody;
use OpenApi\Attributes as OA;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1Post extends Post
{
    public function __construct(
        string $entityName,
        ?string $path = null,
        ?string $description = null,
        ?string $summary = null,
        ?array $requiredFields = null,
        ?array $tags = null,
        ?array $parameters = null,
        ?array $responses = null,
        ?array $examples400 = null,
        ?array $examples422 = null,
    ) {
        $hasIdentifier = str_ends_with(rtrim($path, '/'), '{identifier}');

        if ($hasIdentifier) {
            $responses = array_merge($responses ?? [], [
                new Error404Response($entityName),
            ]);
            $examples400 = array_merge($examples400 ?? [], []);
            $examples422 = array_merge($examples422 ?? [], [
                new ResponseExample('IdentifierPayloadIdMissmatch')
            ]);
        }
        $requestBody = new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/' . $entityName),
                    new OA\Schema(
                        properties: [
                            new OA\Property(
                                property: 'id',
                                ref: '#/components/schemas/New' . $entityName . 'UUID',
                            )
                        ]
                    ),
                ]
            )
        );

        $successResponse = new SuccessResponse(
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
            ],
            links: [
                new OA\Link(
                    link: 'Get' . $entityName . 'ByIdentifier',
                    operationId: 'get' . $entityName,
                    parameters: [
                        'identifier' => '$response.header.X-Resource-Identifier'
                    ],
                    description: 'Retrieve the created contact using the X-Resource-Identifier header'
                ),
                new OA\Link(
                    link: 'Update' . $entityName . 'ByIdentifier',
                    operationId: 'update' . $entityName,
                    parameters: [
                        'identifier' => '$response.header.X-Resource-Identifier'
                    ],
                    description: 'Update the created contact using the X-Resource-Identifier header'
                ),
                new OA\Link(
                    link: 'Delete' . $entityName . 'ByIdentifier',
                    operationId: 'delete' . $entityName,
                    parameters: [
                        'identifier' => '$response.header.X-Resource-Identifier'
                    ],
                    description: 'Delete the created contact using the X-Resource-Identifier header'
                ),
            ]
        );

        if (! empty($requiredFields)) {
            $missingRequestBodyFieldsMessage = 'Invalid request body: ';

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
            operationId: ($hasIdentifier ? 'replace' : 'create') . $entityName,
            description: $description,
            summary: $summary,
            requestBody: $requestBody,
            tags: $tags,
            parameters: $parameters,
            responses: array_merge([
                $successResponse,
                new ErrorResponse(
                    response: 400,
                    examples: array_merge([
                        new ResponseExample('InvalidRequestBodyFormat'),
                        new ResponseExample('UnexpectedQueryParameter'),
                    ], $examples400 ?? [])
                ),
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

            ], $responses ?? []),
        );
    }
}
