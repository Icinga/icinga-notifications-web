<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\OpenApiPreprocessor;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;

class AddGlobal401Response
{
    public function __invoke(Analysis $analysis): void
    {
        foreach ($analysis->openapi->paths as $path) {
            foreach ($path->operations() as $operation) {
                // Avoid duplicates
                $already = array_filter(
                    $operation->responses,
                    fn($resp) => $resp->response === 401
                );

                if (! $already) {
                    $operation->responses[] = new OA\Response([
                        'response' => 401,
                        'description' => 'Unauthorized',
                    ]);
                }
            }
        }
    }
}
