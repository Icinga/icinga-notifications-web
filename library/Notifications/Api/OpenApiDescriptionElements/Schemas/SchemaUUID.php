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
//            pattern: '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$', // general
//            pattern: '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89abAB][0-9a-f]{3}-[0-9a-f]{12}$',       // UUIDv4
//            pattern: '^[0-9a-f]{8}-[0-9a-f]{4}-[0-7][0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$',       // UUIDv4
            pattern: '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
            example: $example ?? "123e4567-e89b-42d3-a456-426614174000"
        );
    }
}
