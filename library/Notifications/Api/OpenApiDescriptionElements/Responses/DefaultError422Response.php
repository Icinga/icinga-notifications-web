<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses;

use Attribute;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Responses\Examples\ResponseExample;
use OpenApi\Attributes as OA;
use OpenApi\Attributes\Examples;
use OpenApi\Attributes\JsonContent;
use OpenApi\Attributes\Response;

#[Attribute(Attribute::TARGET_METHOD)]
class DefaultError422Response extends Response
{
    public function __construct(array $extraExamples = [])
    {
        parent::__construct(
            response: 422,
            description: 'Unprocessable Entity',
            content: new JsonContent(
                examples: array_merge(
                    [
                        new ResponseExample('IDParameterInvalidUUID'),
//                        new Examples(
//                            example: 'IDParameterInvalidUUID',
//                            ref: '#/components/examples/IDParameterInvalidUUID'
//                        ),
                    ],
                    $extraExamples
                ),
                ref: '#/components/schemas/ErrorResponse'
            )
        );
    }
}
