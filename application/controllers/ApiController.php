<?php

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpExceptionInterface;
use Icinga\Module\Notifications\Api\ApiCore;
use Icinga\Module\Notifications\Api\V1\OpenApi;
use Icinga\Web\Request;
use ipl\Stdlib\Str;
use ipl\Web\Compat\CompatController;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Zend_Controller_Request_Exception;

class ApiController extends CompatController
{
    /**
     * Handle API requests and route them to the appropriate endpoint class.
     *
     * Processes API requests for the Notifications module, serving as the main entry point for all API interactions.
     *
     * @return never
     */
    public function indexAction(): never
    {
        try {
            $this->assertPermission('notifications/api');

            $request = $this->getRequest();
            if (
                ! $request->isApiRequest()
                && strtolower($request->getParam('endpoint')) !== (new OpenApi())->getEndpoint() // for browser query
            ) {
                $this->httpBadRequest('No API request');
            }

            $params = $request->getParams();
            $version = ucfirst(Str::camel($params['version']));
            $endpoint = ucfirst(Str::camel($params['endpoint']));
            $identifier = $params['identifier'] ?? null;

            $module = (($moduleName = $request->getModuleName()) !== null)
                ? 'Module\\' . ucfirst($moduleName) . '\\'
                : '';
            $className = sprintf('Icinga\\%sApi\\%s\\%s', $module, $version, $endpoint);

            if (! class_exists($className) || ! is_subclass_of($className, ApiCore::class)) {
                $this->httpNotFound(404, "Endpoint $endpoint does not exist.");
            }

            $serverRequest = (new ServerRequest(
                method: $request->getMethod(),
                uri: $request->getRequestUri(),
                headers: ['Content-Type' => $request->getHeader('Content-Type')],
                serverParams: $request->getServer(),
            ))
                ->withParsedBody($this->getRequestBody($request))
                ->withAttribute('identifier', $identifier);

            $response = (new $className())->handle($serverRequest);
        } catch (HttpExceptionInterface $e) {
            $response = new Response(
                status: $e->getStatusCode(),
                headers: $e->getHeaders(),
                body: json_encode([
                    'message' => $e->getMessage(),
                ])
            );
        } catch (Throwable $e) {
            $response = new Response(
                status: 500,
                body: json_encode([
                    'message' => $e->getMessage(),
                ])
            );
        } finally {
            $this->emitResponse($response);
        }

        exit;
    }

    /**
     * Validate that the request has an appropriate body.
     *
     * @param Request $request The request object to validate.
     *
     * @return ?array The validated JSON content as an associative array.
     *
     * @throws HttpBadRequestException
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
        } catch (Exception) {
            $this->httpBadRequest('Invalid request body: given content is not a valid JSON');
        }

        return $data;
    }

    /**
     * Emit the HTTP response to the client.
     *
     * @param ResponseInterface $response The response object to emit.
     *
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
        header('Content-Type: application/json');

        echo $response->getBody();
    }
}
