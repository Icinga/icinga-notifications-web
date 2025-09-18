<?php

namespace Icinga\Module\Notifications\Api;

use GuzzleHttp\Psr7\Response;
use Icinga\Module\Notifications\Api\Elements\HttpMethod;
use ipl\Sql\Connection;

abstract class ApiCore
{
    /**
     * HTTP GET method
     * This constant represents the HTTP GET method.
     * @var string
     */
    public const GET = 'GET';
    /**
     * HTTP POST method
     * This constant represents the HTTP POST method.
     * @var string
     */
    public const POST = 'POST';
    /**
     * HTTP PUT method
     * This constant represents the HTTP PUT method.
     * @var string
     */
    public const PUT = 'PUT';
    /**
     * HTTP DELETE method
     * This constant represents the HTTP DELETE method.
     * @var string
     */
    public const DELETE = 'DELETE';

    /**
     * The database connection used for API operations.
     *
     * @var Connection
     */
    private Connection $db;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize the API core.
     *
     * This method is called in the constructor and should be implemented by subclasses
     * to perform any necessary initialization tasks.
     * E.g., establishing a database connection and adding Response data.
     *
     * @return void
     */
    abstract protected function init(): void;

    /**
     * Get the database connection
     *
     * This method returns the database connection that is used for API operations.
     *
     * @return Connection
     */
    protected function getDB(): Connection
    {
        return $this->db;
    }

    /**
     * Set the database connection
     *
     * This method sets the database connection that will be used for API operations.
     *
     * @param Connection $db
     * @return static
     */
    protected function setDB(Connection $db): static
    {
        $this->db = $db;

        return $this;
    }

    /**
     * Get allowed HTTP methods for the API.
     *
     * @return string
     */
    protected function getAllowedMethods(): string
    {
        $methods = [];
        foreach (HttpMethod::cases() as $method) {
            if (method_exists($this, $method->name)) {
                $methods[] = $method->value;
            }
        }

        return implode(', ', $methods);
    }

    /**
     * Create a Response object from the given data array.
     *
     * The data array can contain the following keys:
     * - 'status': The HTTP status code (default: 200)
     * - 'headers': An associative array of HTTP headers (default: empty array)
     * - 'body': The response body as null|StreamInterface|resource|string (default: null)
     *
     * @param array $data
     * @return Response
     */
    protected function createResponse(array $data): Response
    {
        $status = $data['status'] ?? 200;
        $headers = $data['headers'] ?? [];
        $body = $data['body'] ?? null;

        return new Response($status, $headers, $body);
    }
}
