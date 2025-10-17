<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Middleware;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Json\JsonDecodeException;
use Icinga\Module\Notifications\Api\V1\OpenApi;
use Icinga\Web\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * LegacyRequestConversionMiddleware is a middleware that converts a legacy request
 * to a PSR-7 request implementing the ServerRequestInterface.
 */
class LegacyRequestConversionMiddleware implements MiddlewareInterface
{
    private Request $legacyRequest;

    public function __construct(Request $legacyRequest)
    {
        $this->legacyRequest = $legacyRequest;
    }

    /**
     * @throws HttpBadRequestException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            ! $this->legacyRequest->isApiRequest()
            && strtolower($this->legacyRequest->getParam('endpoint')) !== (new OpenApi())->getEndpoint()
        ) {
            throw new HttpBadRequestException('No API request');
        }

        $httpMethod = $this->legacyRequest->getMethod();
        $serverRequest = (new ServerRequest(
            $httpMethod,
            $this->legacyRequest->getRequestUri(),
            serverParams: $this->legacyRequest->getServer()
        ))
            ->withAttribute('route_params', $this->legacyRequest->getParams());

        try {
            if ($contentType = $this->legacyRequest->getHeader('Content-Type')) {
                $serverRequest = $serverRequest->withHeader('Content-Type', $contentType);
            }

            $requestBody = $this->legacyRequest->getPost();
        } catch (JsonDecodeException) {
            throw new HttpBadRequestException('Invalid request body: given content is not a valid JSON');
        } catch (\Zend_Controller_Request_Exception) {
            throw new HttpBadRequestException('Invalid request header: Content-Type must be application/json');
        }

        if ($httpMethod === 'POST' || $httpMethod === 'PUT') {
            $serverRequest = $serverRequest->withParsedBody($requestBody);
        } else {
            if (! empty($requestBody)) {
                throw new HttpBadRequestException(
                    'Invalid request body: body is only allowed for POST and PUT requests'
                );
            }
        }

        return $handler->handle($serverRequest);
    }
}
