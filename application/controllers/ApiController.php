<?php

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpExceptionInterface;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\V1\ApiV1;
use Icinga\Module\Notifications\Api\V1\OpenApi;
use Icinga\Util\Json;
use Icinga\Web\Request;
use ipl\Stdlib\Str;
use ipl\Web\Compat\CompatController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
     * @throws JsonEncodeException
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
            $version = ucfirst($params['version']);
            $endpoint = ucfirst(Str::camel($params['endpoint']));
            $identifier = $params['identifier'] ?? null;

            $module = (($moduleName = $request->getModuleName()) !== null)
                ? 'Module\\' . ucfirst($moduleName) . '\\'
                : '';
            $className = sprintf('Icinga\\%sApi\\%s\\%s', $module, $version, $endpoint);

            // TODO: works only for V1 right now
            if (! class_exists($className) || ! is_subclass_of($className, RequestHandlerInterface::class)) {
                $this->httpNotFound("Endpoint $endpoint does not exist.");
            }

            $serverRequest = (new ServerRequest(
                $request->getMethod(),
                $request->getRequestUri(),
                ['Content-Type' => $request->getHeader('Content-Type')],
                serverParams: $request->getServer(),
            ))
                ->withParsedBody($this->getRequestBody($request))
                ->withAttribute('identifier', $identifier);

            $response = (new $className())->handle($serverRequest);
        } catch (HttpExceptionInterface $e) {
            $response = new Response(
                $e->getStatusCode(),
                array_merge($e->getHeaders(), ['Content-Type' => 'application/json']),
                Json::sanitize(['message' => $e->getMessage()])
            );
        } catch (Throwable $e) {
            $response = new Response(
                500,
                ['Content-Type' => 'application/json'],
                Json::sanitize(['message' => $e->getMessage()])
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
