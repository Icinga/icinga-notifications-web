<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters;

use OpenApi\Attributes\Parameter;
use OpenApi\Generator;
use OpenApi\Attributes as OA;

class PathParameter extends Parameter
{
    public function __construct(
        ?string $parameter = null,
        ?string $name = null,
        ?string $description = null,
        ?string $identifierSchema = null,
        ?bool $required = null,
        ?string $example = null,
    ) {
        $params = [
            'parameter' => $parameter ?? Generator::UNDEFINED,
                'name' => $name ?? Generator::UNDEFINED,
                'description' => $description ?? Generator::UNDEFINED,
                'in' => 'path',
                'required' => $required ?? true,
                'schema' => new OA\Schema(ref: '#/components/schemas/' . $identifierSchema ?? 'UUID'),
        ];

        $params = $example !== null ? array_merge($params, ['example' => $example]) : $params;

        parent::__construct(...$params);
    }
}
