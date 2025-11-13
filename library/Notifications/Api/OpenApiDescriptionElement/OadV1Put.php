<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Error404Response;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\ErrorResponse;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\Example\ResponseExample;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response\SuccessResponse;
use OpenApi\Attributes as OA;
use OpenApi\Attributes\Put;
use OpenApi\Attributes\RequestBody;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OadV1Put extends Put
{
    public function __construct(
        string $entityName,
        ?string $path = null,
        ?string $description = null,
        ?string $summary = null,
        ?RequestBody $requestBody = null,
        ?array $tags = null,
        ?array $parameters = null,
        ?array $responses = null,
        ?array $examples400 = null,
        ?array $examples422 = null,
    ) {
        parent::__construct(
            path: $path,
            operationId: 'update' . $entityName,
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
                            new OA\Header(
                                header: 'X-Resource-Identifier',
                                description: 'The identifier of the created ' . $entityName,
                                schema: new OA\Schema(
                                    type: 'string',
                                    format: 'uuid',
                                )
                            ),
                            new OA\Header(
                                header: 'Location',
                                description: 'The URL of the created ' . $entityName,
                                schema: new OA\Schema(
                                    type: 'string',
                                    format: 'url',
                                    example: 'notifications/api/v1/' . strtolower($entityName) . 's/{identifier}',
                                )
                            )
                        ],
                        links: [
                            new OA\Link(
                                link: 'Get' . $entityName . 'ByIdentifiere',
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
                                new ResponseExample('InvalidRequestBodyFieldFormat'),
                                new ResponseExample('InvalidRequestBodyId'),
                                new ResponseExample('IdentifierMismatch'),
                                new ResponseExample('MissingRequiredRequestBodyField')
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
