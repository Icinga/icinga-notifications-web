<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Middleware;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Module\Notifications\Api\EndpointInterface;
use Icinga\Module\Notifications\Common\HttpMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use ValueError;

/**
 * ValidationMiddleware is a middleware that validates incoming HTTP requests
 * for an API. It checks the HTTP method, query parameters, and request body to ensure
 * they conform to expected formats and rules.
 */
class ValidationMiddleware implements MiddlewareInterface
{
    /**
     * @throws HttpBadRequestException
     * @throws HttpException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $endpointHandler = $request->getAttribute('endpointHandler');

        if (! $endpointHandler instanceof EndpointInterface) {
            throw new HttpBadRequestException("No endpoint resolved");
        }

        $request = $this->validateHttpMethod($request, $endpointHandler);

        $this->assertValidRequest($request);

        return $handler->handle($request);
    }

    /**
     * Validate the HTTP method of the request.
     *
     * @param ServerRequestInterface $request
     * @param EndpointInterface $endpointHandler
     *
     * @return ServerRequestInterface
     *
     * @throws HttpException
     */
    private function validateHttpMethod(
        ServerRequestInterface $request,
        EndpointInterface $endpointHandler
    ): ServerRequestInterface {
        try {
            $httpMethod = HttpMethod::fromRequest($request);
        } catch (ValueError) {
            throw (new HttpException(405, sprintf('HTTP method %s is not supported', $request->getMethod())))
                ->setHeader('Allow', implode(', ', $endpointHandler->getAllowedMethods()));
        }

        $request = $request->withAttribute('httpMethod', $httpMethod);

        if (! in_array($httpMethod->uppercase(), $endpointHandler->getAllowedMethods())) {
            throw (new HttpException(
                405,
                sprintf(
                    'Method %s is not supported for endpoint %s',
                    $httpMethod->uppercase(),
                    $endpointHandler->getEndpoint()
                )
            ))
                ->setHeader('Allow', implode(', ', $endpointHandler->getAllowedMethods()));
        }

        return $request;
    }

    /**
     * Assert that the request has a valid format.
     *
     * @param ServerRequestInterface $request
     *
     * @return void
     *
     * @throws HttpBadRequestException
     */
    private function assertValidRequest(ServerRequestInterface $request): void
    {
        $httpMethod = $request->getAttribute('httpMethod');
        $identifier = $request->getAttribute('identifier');
        $queryFilter = $request->getUri()->getQuery();

        if ($httpMethod !== HttpMethod::GET && ! empty($queryFilter)) {
            throw new HttpBadRequestException(
                'Unexpected query parameter: Filter is only allowed for GET requests'
            );
        }

        if ($httpMethod === HttpMethod::GET && ! empty($identifier) && ! empty($queryFilter)) {
            throw new HttpBadRequestException(sprintf(
                'Invalid request: %s with identifier and query parameters, it\'s not allowed to use both together.',
                $httpMethod->uppercase()
            ));
        }

        if (
            ! in_array($httpMethod, [HttpMethod::PUT, HttpMethod::POST])
            && (! empty($request->getBody()->getSize()) || ! empty($request->getParsedBody()))
        ) {
            throw new HttpBadRequestException('Invalid request: Body is only allowed for POST and PUT requests');
        }

        if (in_array($httpMethod, [HttpMethod::PUT, HttpMethod::DELETE]) && empty($identifier)) {
            throw new HttpBadRequestException("Invalid request: Identifier is required");
        }

        if ((! empty($identifier) || $identifier === '0') && ! Uuid::isValid($identifier)) {
            throw new HttpBadRequestException('The given identifier is not a valid UUID');
        }
    }
}
