<?php

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Schemas;

use Attribute;
use OpenApi\Attributes\Schema;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class SchemaUUID extends Schema
{
    public function __construct(
        string $entityName,
        ?string $example = null,
    ) {
        $name = $entityName . 'UUID';
        parent::__construct(
            schema: $name,
            title: $name,
            description: 'An UUID representing a notification ' . $entityName,
            type: 'string',
            format: 'uuid',
            maxLength: 36,
            minLength: 36,
            example: $example
        );
    }
}
