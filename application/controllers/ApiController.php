<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Api\Middleware\DispatchMiddleware;
use Icinga\Module\Notifications\Api\Middleware\EndpointExecutionMiddleware;
use Icinga\Module\Notifications\Api\Middleware\ErrorHandlingMiddleware;
use Icinga\Module\Notifications\Api\Middleware\LegacyRequestConversionMiddleware;
use Icinga\Module\Notifications\Api\Middleware\MiddlewarePipeline;
use Icinga\Module\Notifications\Api\Middleware\RoutingMiddleware;
use Icinga\Module\Notifications\Api\Middleware\ValidationMiddleware;
use Icinga\Security\SecurityException;
use ipl\Web\Compat\CompatController;
use Psr\Http\Message\ResponseInterface;

class ApiController extends CompatController
{
    /**
     * Handle API requests and route them to the appropriate endpoint class.
     *
     * Processes API requests for the Notifications module, serving as the main entry point for all API interactions.
     *
     * @return never
     *
     * @throws SecurityException
     */
    public function indexAction(): never
    {
        $this->assertPermission('notifications/api');

        $pipeline = new MiddlewarePipeline([
            new ErrorHandlingMiddleware(),
            new LegacyRequestConversionMiddleware($this->getRequest()),
            new RoutingMiddleware(),
            new DispatchMiddleware(),
            new ValidationMiddleware(),
            new EndpointExecutionMiddleware(),
        ]);

        $this->emitResponse($pipeline->execute());

        exit;
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
        do {
            ob_end_clean();
        } while (ob_get_level() > 0);

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }
        header('Content-Type: application/json');

        $body = $response->getBody();
        while (! $body->eof()) {
            echo $body->read(8192);
        }
    }
}
