<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses;

use cebe\openapi\spec\Parameter;
use OpenApi\Attributes\Response;
use OpenApi\Attributes as OA;

class SuccessDataResponse extends Response
{
    public function __construct(
        string $entityName,
        bool $multipleResults = false,
    ) {
        if ($multipleResults) {
            $content = new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'data',
                        description: sprintf('Successful response with an array of %s objects', $entityName),
                        type: 'array',
                        items: new OA\Items(
                            ref: '#/components/schemas/' . $entityName,
                        ),
                    ),
                ]
            );
            $description = sprintf('Successful response with multiple %s results', $entityName);
        } else {
            $content = new OA\JsonContent(
                ref: '#/components/schemas/' . $entityName,
                description: sprintf('Successfull response with the %s object', $entityName),
            );
            $description = sprintf('Successful response with a single %s result', $entityName);
        }
        parent::__construct(
            response: 200,
            description: $description,
            content: $content,
            links: [
                new OA\Link(
                    operationId: 'list' . $entityName,
                    parameters: [
                        new OA\Parameter(
                            parameter: 'id',
                            ref: '#/components/schema/' . $entityName,
                        )
                    ],
                    description: 'Link to the endpoint to retrieve multiple ' . $entityName . ' objects'
                )
            ]
        );
    }
}
