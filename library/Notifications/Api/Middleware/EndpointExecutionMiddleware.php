<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * EndpointExecutionMiddleware is a middleware that executes the endpoint handler.
 * It checks if the endpoint handler is an instance of RequestHandlerInterface and
 * delegates the request processing to the handler if it is.
 */
class EndpointExecutionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $endpointHandler = $request->getAttribute('endpointHandler');

        if (! $endpointHandler instanceof RequestHandlerInterface) {
            return $handler->handle($request);
        }
        return $request->getAttribute('endpointHandler')->handle($request);
    }
}
