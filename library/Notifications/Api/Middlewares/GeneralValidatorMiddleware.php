<?php

namespace Icinga\Module\Notifications\Api\Middlewares;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Json\JsonDecodeException;
use Icinga\Module\Notifications\Api\Elements\HttpError;
use Icinga\Module\Notifications\Api\Elements\HttpMethod;
use Ramsey\Uuid\Uuid;
use Icinga\Util\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GeneralValidatorMiddleware is a middleware that validates incoming HTTP requests
 * for an API. It checks the HTTP method, query parameters, and request body to ensure
 * they conform to expected formats and rules.
 *
 * This middleware performs the following validations:
 * - Ensures the HTTP method is one of GET, POST, PUT, or DELETE.
 * - Validates that filters are only used with GET requests.
 * - Ensures that identifiers are provided for PUT and DELETE requests
 * - Validates that the Content-Type is application/json for POST and PUT requests.
 * - Validates that the identifier, if provided, is a valid UUID.
 * - Parses and validates the request body for POST and PUT requests.
 *
 * If any validation fails, it throws an appropriate HTTP exception (e.g., 400 Bad Request or 405 Method Not Allowed).
 * If all validations pass, it adds the validated identifier and parsed request body to the request attributes
 * and passes the request to the next handler in the middleware chain.
 */
class GeneralValidatorMiddleware implements MiddlewareInterface
{
    /**
     * @throws HttpException
     * @throws HttpBadRequestException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $httpMethod = $request->getMethod();
        $filterStr = $request->getUri()->getQuery();
        $identifier = $request->getAttribute('identifier');

        if (HttpMethod::tryFrom($httpMethod) === null) {
            throw new HttpException(405, "HTTP method $httpMethod is not supported.");
        }

        if ($httpMethod !== HttpMethod::get->value && ! empty($filterStr)) {
            throw HttpBadRequestException::create(
                ['Invalid request parameter: Filter is only allowed for GET requests']
            );
        } elseif ($httpMethod === HttpMethod::get->value && ! empty($identifier) && ! empty($filterStr)) {
            throw HttpBadRequestException::create([
                "Invalid request: $httpMethod with identifier and query parameters,"
                . " it's not allowed to use both together."
            ]);
        } elseif (in_array($httpMethod, [HttpMethod::put->value, HttpMethod::delete->value]) && empty($identifier)) {
            throw HttpBadRequestException::create(["Invalid request: Identifier is required"]);
        } elseif (
            in_array($httpMethod, [HttpMethod::put->value, HttpMethod::post->value])
            && $request->getHeaderLine('Content-Type') !== 'application/json'
        ) {
            throw HttpBadRequestException::create(['Invalid request header: Content-Type must be application/json']);
        }

        if (! empty($identifier) && ! Uuid::isValid($identifier)) {
            throw HttpBadRequestException::create(['The given identifier is not a valid UUID']);
        }
        $requestBody = $this->getValidRequestBody($request);

        return $handler->handle(
            $request
                ->withAttribute('validIdentifier', $identifier)
                ->withParsedBody($requestBody)
        );
    }

    /**
     * Validate that the request has a JSON content type and return the parsed JSON content.
     *
     * @param ServerRequestInterface $request The request object to validate.
     * @return array The validated JSON content as an associative array.
     * @throws HttpBadRequestException If the content type is not application/json.
     */
    private function getValidRequestBody(ServerRequestInterface $request): array
    {
        if (! empty($parsedBody = $request->getParsedBody()) && is_array($parsedBody)) {
            return $parsedBody;
        } elseif (empty($request->getBody()->getSize()) && empty($request->getParsedBody())) {
            return [];
        }

        $msgPrefix = 'Invalid request body: ';
        if (
            ! preg_match('/([^;]*);?/', $request->getHeaderLine('Content-Type'), $matches)
            || $matches[1] !== 'application/json'
        ) {
            throw HttpBadRequestException::create(['Invalid request header: Content-Type must be application/json']);
        }
        $body = $request->getBody()->getContents();
        if (empty($body)) {
            throw HttpBadRequestException::create([$msgPrefix . 'given content is empty']);
        }

        try {
            $validBody = Json::decode($body, true);
        } catch (JsonDecodeException $e) {
            throw HttpBadRequestException::create([$msgPrefix . 'given content is not a valid JSON']);
        }

        return $validBody;
    }
}
