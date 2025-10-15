<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters;

use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\Schema;
use OpenApi\Generator;
use OpenApi\Attributes as OA;

class PathParameter extends Parameter
{
    public function __construct(
        ?string $parameter = null,
        ?string $name = null,
        ?string $description = null,
        ?bool $required = null,
        ?string $identifierSchema = null,
        ?Schema $schema = null,
        ?string $example = null,
    ) {
        $schema = $identifierSchema !== null
        ? new OA\Schema(ref: '#/components/schemas/' . $identifierSchema)
        : ($schema !== null ? $schema : new OA\Schema(type: 'string'));

        $params = [
            'parameter' => $parameter ?? Generator::UNDEFINED,
                'name' => $name ?? Generator::UNDEFINED,
                'description' => $description ?? Generator::UNDEFINED,
                'in' => 'path',
                'required' => $required ?? true,
                'schema' => $schema,
        ];

        $params = $example !== null ? array_merge($params, ['example' => $example]) : $params;

        parent::__construct(...$params);
    }
}
