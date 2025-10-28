<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Middleware;

use ipl\Stdlib\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * RoutingMiddleware is a middleware that processes the request and extracts
 * the version, endpoint, and identifier from the route parameters.
 */
class RoutingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getAttribute('route_params');
        $version = ucfirst($params['version']);
        $endpoint = ucfirst(Str::camel($params['endpoint']));
        $identifier = $params['identifier'] ?? null;

        return $handler->handle(
            $request
                ->withAttribute('version', ucfirst($version))
                ->withAttribute('endpoint', ucfirst($endpoint))
                ->withAttribute('identifier', $identifier !== null ? strtolower($identifier) : null)
        );
    }
}
