<?php

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Api\ApiCore;
use Icinga\Security\SecurityException;
use Icinga\Util\StringHelper;
use Icinga\Web\Request;
use Icinga\Web\Response;
use ipl\Stdlib\Str;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ReflectionClass;
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
     * @throws HttpException|HttpNotFoundException
     * @throws Zend_Controller_Request_Exception
     */
    public function indexAction(): never
    {
        $this->assertPermission('notifications/api');
        $request = $this->getRequest();
        if (! $request->isApiRequest() && strtolower($request->getParam('endpoint')) !== ApiCore::OPENAPI_ENDPOINT) {
            $this->httpBadRequest('No API request');
        }

        $this->dispatchEndpoint($request, $this->getResponse());

        exit();
    }

    /**
     * Dispatch the API endpoint based on the request parameters.
     *
     * @param Request $request The request object containing parameters.
     * @param Response $response The response object to send back.
     * @throws HttpBadRequestException|HttpNotFoundException|HttpException|
     * @throws Zend_Controller_Request_Exception
     */
    private function dispatchEndpoint(Request $request, Response $response): void
    {
        $params = $request->getParams();
        $method = $request->getMethod();
        $methodName = strtolower($method);
        $moduleName = $request->getModuleName();

        $version = Str::camel($params['version']);
        $endpoint = Str::camel($params['endpoint']);
        $identifier = $params['identifier'] ?? null;

        $module = ($moduleName !== null) ? 'Module\\' . ucfirst($moduleName) . '\\' : '';
        $className = sprintf('Icinga\\%sApi\\%s\\%s', $module, $version, $endpoint);

        // Check if the required class and method are available and valid
        if (! class_exists($className) || ! is_subclass_of($className, ApiCore::class)) {
            $this->httpNotFound(404, "Endpoint $endpoint does not exist.");
        }

        // TODO: move this to an api core or version class?
        $parsedMethodName = ($method === 'GET' && empty($identifier)) ? $methodName . 'Any' : $methodName;

        if (! in_array($parsedMethodName, get_class_methods($className))) {
            if ($method === 'GET' && in_array($methodName, get_class_methods($className))) {
                $parsedMethodName = $methodName;
            } else {
                throw new HttpException(405, "Method $method does not exist.");
            }
        }

        // Choose the correct constructor call based on the endpoint
        if (in_array($method, ['POST', 'PUT'])) {
            $data = $this->getValidatedJsonContent($request);
            (new $className($request, $response))->$parsedMethodName($data);
        } else {
            (new $className($request, $response))->$parsedMethodName();
        }
    }

    /**
     * Validate that the request has a JSON content type and return the parsed JSON content.
     *
     * @param Request $request The request object to validate.
     * @return array The validated JSON content as an associative array.
     * @throws HttpBadRequestException|Zend_Controller_Request_Exception If the content type is not application/json.
     */
    private function getValidatedJsonContent(Request $request): array
    {
        $msgPrefix = 'Invalid request body: ';

        if (
            ! preg_match('/([^;]*);?/', $request->getHeader('Content-Type'), $matches)
            || $matches[1] !== 'application/json'
        ) {
            $this->httpBadRequest($msgPrefix . 'Content-Type must be application/json');
        }

        try {
            $data = $request->getPost();
        } catch (Exception $e) {
            $this->httpBadRequest($msgPrefix . 'given content is not a valid JSON');
        }

        return $data;
    }
}
