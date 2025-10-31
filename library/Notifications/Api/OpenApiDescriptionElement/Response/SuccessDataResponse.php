<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response;

use OpenApi\Attributes\Response;
use OpenApi\Attributes as OA;

class SuccessDataResponse extends Response
{
    public function __construct(
        string $entityName,
        bool $multipleResults = true,
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
                properties: [
                    new OA\Property(
                        property: 'data',
                        ref: '#/components/schemas/' . $entityName,
                        description: sprintf('Successfull response with the %s object', $entityName),
                        type: 'object',
                    ),
                ]
            );
            $description = sprintf('Successful response with a single %s result', $entityName);
        }
        parent::__construct(
            response: 200,
            description: $description,
            content: $content,
        );
    }
}
