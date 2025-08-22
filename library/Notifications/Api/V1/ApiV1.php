<?php

namespace Icinga\Module\Notifications\Api\V1;

use Exception;
use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Module\Notifications\Api\ApiCore;
use Icinga\Web\Response;
use ipl\Sql\Compat\FilterProcessor;
use ipl\Web\Filter\QueryString;
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
    public function __construct(
        Response $response,
        /**
         * The filter string used to filter results.
         * This is typically a query string parameter that specifies conditions for filtering.
         *
         * @var string|null
         */
        protected ?string $filterStr = null,
        /**
         * The identifier for the resource being accessed.
         *
         * @var string|null
         */
        protected ?string $identifier = null
    ) {
        parent::__construct($response);
        $this->version = 'v1';
//        var_dump($response, $params, $identifier);
        $this->validateIdentifier();
    }

    /**
     * Get the Response object
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Validate the identifier to ensure it is a valid UUID.
     *
     * If the identifier is not valid, it will throw a Bad Request HTTP exception.
     *
     * @return void
     * @throws HttpBadRequestException
     */
    protected function validateIdentifier(): void
    {
        if (($i = $this->identifier) && !Uuid::isValid($i)) {
            $this->httpBadRequest('The given identifier is not a valid UUID');
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
        if (!empty($this->filterStr)) {
            try {
                $filterRule = QueryString::fromString($this->filterStr)
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
