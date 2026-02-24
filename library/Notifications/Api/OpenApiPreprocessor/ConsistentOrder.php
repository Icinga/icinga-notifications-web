<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\OpenApiPreprocessor;

use OpenApi\Analysis;

class ConsistentOrder
{
    public function __invoke(Analysis $analysis): void
    {
        if (is_object($analysis->openapi->components) && is_iterable($analysis->openapi->components->schemas)) {
            usort($analysis->openapi->components->schemas, function ($a, $b) {
                return $a->schema <=> $b->schema;
            });
        }

        usort($analysis->openapi->paths, function ($a, $b) {
            return $a->path <=> $b->path;
        });

        usort($analysis->openapi->tags, function ($a, $b) {
            return $a->name <=> $b->name;
        });
    }
}
