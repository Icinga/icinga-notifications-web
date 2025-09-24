<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters;

use OpenApi\Attributes\Parameter;
use OpenApi\Attributes as OA;
use OpenApi\Attributes\Schema;

class QueryParameter extends Parameter
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
            'parameter' => $parameter,
            'name' => $name,
            'description' => $description,
            'in' => 'query',
            'required' => $required ?? false,
            'schema' => $schema,
        ];

         $params = $example !== null ? array_merge($params, ['example' => $example]) : $params;

         parent::__construct(...$params);
    }
}
