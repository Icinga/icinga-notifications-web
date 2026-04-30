<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Response;

use OpenApi\Attributes\Examples;
use OpenApi\Attributes\JsonContent;
use OpenApi\Attributes\Response;

class Error404Response extends Response
{
    public function __construct(?string $endpointName = null)
    {
        parent::__construct(
            response: 404,
            description: $endpointName . ' Not Found',
            content: new JsonContent(
                examples: [
                    new Examples(
                        example: 'ResourceNotFound',
                        summary: 'Resource not found',
                        value: ['message' => $endpointName . ' not found'],
                    )
                ],
                ref: '#/components/schemas/ErrorResponse'
            )
        );
    }
}
