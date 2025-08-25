<?php

namespace Icinga\Module\Notifications\Api\V1;

use Exception;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Module\Notifications\Api\ApiCore;
use ipl\Sql\Compat\FilterProcessor;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use Ramsey\Uuid\Uuid;
use OpenApi\Attributes as OA;

/**
 * Base class for API version 1.
 *
 * This class provides common functionality for API endpoints in version 1 of the Icinga Notifications API.
 * It includes methods for handling responses, validating identifiers, and creating filters from query strings.
 *
 * @package Icinga\Module\Notifications\Api\V1
 */
#[OA\OpenApi(
    info: new OA\Info(
        version: "1.0.0",
        description: "API for managing notification channels",
        title: "Icinga Notifications API",
    ),
    servers: [
        new OA\Server(
            url: "http://localhost/icingaweb2/notifications/api/v1",
            description: "Local server",
        )
    ],
    security: [
        new OA\SecurityScheme(
            ref: '#/components/securitySchemes/BasicAuth',
        ),
    ],
)]
#[OA\Tag(
    name: "Contacts",
    description: "Operations related to notification Contacts"
)]
#[OA\Tag(
    name: "Contactgroups",
    description: "Operations related to notification contactgroups"
)]
#[OA\Tag(
    name: "Channels",
    description: "Operations related to notification channels"
)]
#[OA\SecurityScheme(
    securityScheme: 'BasicAuth',
    type: 'http',
    description: 'Basic authentication for API access',
    name: 'BasicAuth',
    scheme: 'basic',
)]
abstract class ApiV1 extends ApiCore
{
    protected string $identifier;

    /**
     * Initialize the API
     *
     * @return void
     * @throws HttpBadRequestException
     */
    protected function init(): void
    {
        $this->version = 'v1';
        $this->validateIdentifier();
        $method = $this->getRequest()->getMethod();
        $filterStr = Url::fromRequest()->getQueryString();

        // Validate that Method with parameters or identifier is allowed
        if ($method !== 'GET' && ! empty($filterStr)) {
            $this->httpBadRequest(
                "Invalid request: $method with query parameters, only GET is allowed with query parameters."
            );
        } elseif ($method === 'GET' && ! empty($this->identifier) && ! empty($filterStr)) {
            $this->httpBadRequest(
                "Invalid request: $method with identifier and query parameters, it's not allowed to use both together."
            );
        }
    }

    /**
     * Validate the identifier to ensure it is a valid UUID.
     * If the identifier is not valid, it will throw a Bad Request HTTP exception.
     * If a valid identifier is provided, it will be stored in the `identifier` property.
     *
     * @return void
     * @throws HttpBadRequestException
     */
    protected function validateIdentifier(): void
    {
        if ($identifier = $this->getRequest()->getParams()['identifier'] ?? null) {
            if (! Uuid::isValid($identifier)) {
                $this->httpBadRequest('The given identifier is not a valid UUID');
            }
            $this->identifier = $identifier;
        }
    }

    /**
     * Create a filter from the filter string.
     *
     * This method parses the filter string and returns an array of filter rules.
     * If the filter string is empty, it returns false.
     *
     * @param callable $listener A listener function to handle conditions in the query string.
     * @return array|bool Returns an array of filter rules or false if no filter string is provided.
     * @throws HttpBadRequestException If the filter string cannot be parsed.
     */
    protected function createFilterFromFilterStr(callable $listener): array|bool
    {
        if (! empty($filterStr = Url::fromRequest()->getQueryString())) {
            try {
                $filterRule = QueryString::fromString($filterStr)
                    ->on(
                        QueryString::ON_CONDITION,
                        $listener
                    )->parse();

                return FilterProcessor::assembleFilter($filterRule);
            } catch (Exception $e) {
                $this->httpBadRequest($e->getMessage());
            }
        }
        return false;
    }
}
