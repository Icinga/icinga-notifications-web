<?php

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Utils;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Api\ApiCore;
use Icinga\Security\SecurityException;
use Icinga\Web\Request;
use ipl\Stdlib\Str;
use ipl\Web\Compat\CompatController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Zend_Controller_Request_Exception;

class ApiController extends CompatController
{
    /**
     * Index action for the API controller.
     *
     * This method handles API requests, validates that the request has a valid format,
     * and dispatches the appropriate endpoint based on the request method and parameters.
     *
     * @return never
     * @throws HttpBadRequestException If the request is not valid.
     * @throws SecurityException
     * @throws HttpNotFoundException
     * @throws Zend_Controller_Request_Exception
     */
    public function indexAction(): never
    {
        $this->assertPermission('notifications/api');
        $request = $this->getRequest();
        if (! $request->isApiRequest() && strtolower($request->getParam('endpoint')) !== ApiCore::OPENAPI_ENDPOINT) {
            $this->httpBadRequest('No API request');
        }

        $this->dispatchEndpoint($request);

        exit();
    }

    /**
     * Dispatch the API endpoint based on the request parameters.
     *
     * @param Request $request The request object containing parameters.
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     * @throws Zend_Controller_Request_Exception
     */
    private function dispatchEndpoint(Request $request): void
    {
        $params = $request->getParams();
        $version = Str::camel($params['version']);
        $endpoint = Str::camel($params['endpoint']);
        $identifier = $params['identifier'] ?? null;

        $module = (($moduleName = $request->getModuleName()) !== null) ? 'Module\\' . ucfirst($moduleName) . '\\' : '';
        $className = sprintf('Icinga\\%sApi\\%s\\%s', $module, $version, $endpoint);

        $serverRequest = ((new HttpFactory())->createServerRequest(
            $request->getMethod(),
            $request->getRequestUri(),
            $request->getServer()
        ))
            ->withParsedBody($this->getRequestBody($request))
            ->withAttribute('identifier', $identifier)
            ->withHeader('Content-Type', $request->getHeader('Content-Type'));

//        $serverRequest = empty($stream = $this->getRequestBodyStream($request))
//            ? $serverRequest
//            : $serverRequest->withBody($stream);

        if (! class_exists($className) || ! is_subclass_of($className, ApiCore::class)) {
            $this->httpNotFound(404, "Endpoint $endpoint does not exist.");
        }

        $this->emitResponse((new $className())->handle($serverRequest));
    }

    /**
     * Validate that the request has a JSON content type and return the parsed JSON content.
     *
     * @param Request $request The request object to validate.
     * @return ?StreamInterface The parsed JSON content as a StreamInterface, or null if not applicable.
     * @throws HttpBadRequestException If the request content is not valid JSON.
     * @throws Zend_Controller_Request_Exception
     */
    private function getRequestBodyStream(Request $request): ?StreamInterface
    {
        if (
            ! preg_match('/([^;]*);?/', $request->getHeader('Content-Type'), $matches)
            || $matches[1] !== 'application/json'
        ) {
            return null;
        }

        $msgPrefix = 'Invalid request body: ';
        $phpInput = fopen('php://input', 'r');
        $stream = Utils::streamFor($phpInput);
        fclose($phpInput);
        if ($stream->getSize() === 0) {
            $this->httpBadRequest($msgPrefix . 'given content is empty');
        }

        return $stream;
    }

    /**
     * Validate that the request has a JSON content type and return the parsed JSON content.
     *
     * @param Request $request The request object to validate.
     * @return ?array The validated JSON content as an associative array, or null if not applicable.
     * @throws HttpBadRequestException If the request content is not valid JSON.
     * @throws Zend_Controller_Request_Exception
     */
    private function getRequestBody(Request $request): ?array
    {
        if (
            ! preg_match('/([^;]*);?/', $request->getHeader('Content-Type'), $matches)
            || $matches[1] !== 'application/json'
        ) {
            return null;
        }
        try {
            $data = $request->getPost();
        } catch (Exception $e) {
            $this->httpBadRequest('Invalid JSON content: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * Emit the HTTP response to the client.
     *
     * Sends the status code, headers, and body of the response to the client.
     *
     * @param ResponseInterface $response The response object to emit.
     * @return void
     */
    protected function emitResponse(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        echo $response->getBody();
    }
}
