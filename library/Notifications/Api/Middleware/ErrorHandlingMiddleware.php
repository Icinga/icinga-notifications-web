<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Middleware;

use GuzzleHttp\Psr7\Response;
use Icinga\Application\Logger;
use Icinga\Exception\Http\HttpExceptionInterface;
use Icinga\Exception\IcingaException;
use Icinga\Module\Notifications\Api\Exception\InvalidFilterParameterException;
use Icinga\Util\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * ErrorHandlingMiddleware is a middleware that handles errors in the API.
 * It catches exceptions and returns appropriate HTTP responses.
 */
class ErrorHandlingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpExceptionInterface $e) {
            return new Response(
                $e->getStatusCode(),
                array_merge($e->getHeaders(), ['Content-Type' => 'application/json']),
                Json::sanitize(['message' => $e->getMessage()])
            );
        } catch (InvalidFilterParameterException $e) {
            return new Response(
                400,
                ['Content-Type' => 'application/json'],
                Json::sanitize([
                    'message' =>  $e->getMessage()
                ])
            );
        } catch (Throwable $e) {
            Logger::error($e);
            Logger::debug(IcingaException::getConfidentialTraceAsString($e));
            return new Response(
                500,
                ['Content-Type' => 'application/json'],
                Json::sanitize(['message' => $e->getTraceAsString()])
            );
        }
    }
}
