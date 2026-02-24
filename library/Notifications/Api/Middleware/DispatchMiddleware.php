<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\Middleware;

use Icinga\Exception\Http\HttpNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DispatchMiddleware is a middleware that dispatches the request to the appropriate endpoint.
 * It checks if the endpoint class exists and is a subclass of RequestHandlerInterface.
 * If the endpoint class exists, it creates an instance of it and attaches it to the request.
 */
class DispatchMiddleware implements MiddlewareInterface
{
    /**
     * @throws HttpNotFoundException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $version = $request->getAttribute('version');
        $endpoint = $request->getAttribute('endpoint');
        $class = sprintf('Icinga\\Module\\Notifications\\Api\\%s\\%s', $version, $endpoint);

        if (! class_exists($class) || ! is_subclass_of($class, RequestHandlerInterface::class)) {
            throw new HttpNotFoundException("Endpoint $endpoint not found");
        }

        $endpointHandler = new $class();

        return $handler->handle($request->withAttribute('endpointHandler', $endpointHandler));
    }
}
