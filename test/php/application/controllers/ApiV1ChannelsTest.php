<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Test\BaseApiV1TestCase;

class ApiV1ChannelsTest extends BaseApiV1TestCase
{
    /**
     * Get a specific channels by providing a filter.
     *
     * @dataProvider databases
     */
    public function testGetWithMatchingFilter(): void
    {
        // filter by id
        $response = $this->sendRequest('GET', 'channels?id=0817d973-398e-41d7-9cd2-61cdb7ef41a1');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null}]}',
            $content
        );

        // filter by name
        $response = $this->sendRequest('GET', 'channels?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null}]}',
            $content
        );

        // filter by type
        $response = $this->sendRequest('GET', 'channels?type=email');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null}]}',
            $content
        );

        // filter by all available filters together
        $response = $this->sendRequest('GET', 'channels?id=0817d973-398e-41d7-9cd2-61cdb7ef41a1&name=Test&type=email');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null}]}',
            $content
        );
    }

    /**
     * Get all channels currently stored at the endpoint.
     *
     * @dataProvider databases
     */
    public function testGetEverything(): void
    {
        // There are two
        $response = $this->sendRequest('GET', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null},'
            . "\n" . '{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a2","name":"Test2","type":"webhook","config":null}]}',
            $content
        );
    }

    /**
     * Get a specific channel by its identifier.
     *
     * @dataProvider databases
     */
    public function testGetWithMatchingIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'channels/0817d973-398e-41d7-9cd2-61cdb7ef41a1');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null}',
            $content
        );
    }

    /**
     * Get channels, while providing a non-matching name filter.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingFilter(): void
    {

        $response = $this->sendRequest('GET', 'channels?name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame('{"content":[]}', $content);
    }

    // TODO: Additional GET tests
    /**
     * Try to use a non-existing filter.
     *
     * @dataProvider databases
     */
    public function testGetWithInvalidFilter(): void
    {
        $response = $this->sendRequest('GET', 'channels?nonexistingfilter=value');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request parameter: '
            . 'Filter column nonexistingfilter given, only id, name and type are allowed"}',
            $content
        );
    }

    /**
     * Get a specific channel by providing a non-existent identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'channels/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Channel not found"}', $content);
    }

    /**
     * Get a specific channel by providing an invalid identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithInvalidIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'channels/0817d973-398e-41d7-9ef2-61cdb7ef41a234534');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"The given identifier is not a valid UUID"}', $content);
    }

    /**
     * Try to use an identifier with a filter.
     *
     * @dataProvider databases
     */
    public function testGetWithIdentifierAndFilter(): void
    {
        $expectedMessage = '{"status":"error","message":"Invalid request: '
            . 'GET with identifier and query parameters, it\'s not allowed to use both together."}';

        // Valid identifier and valid filter
        $response = $this->sendRequest('GET', 'channels/0817d973-398e-41d7-9cd2-61cdb7ef41a1?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expectedMessage, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest('GET', 'channels/0817d973-398e-41d7-9cd2-61cdb7ef41aa?nonexistingfilter=value');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expectedMessage, $content);
    }

    // TODO: Additional general tests
    /**
     * Try to use a non-supported HTTP method.
     *
     * @dataProvider databases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        // General invalid method
        $response = $this->sendRequest('PATCH', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"HTTP method PATCH is not supported"}',
            $content
        );

        // Endpoint specific invalid method
        //Try to POST without identifier
        $response = $this->sendRequest('POST', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method POST is not supported for endpoint Channels"}',
            $content
        );

        // Try to POST with identifier
        $response = $this->sendRequest('POST', 'channels/0817d973-398e-41d7-9cd2-61cdb7ef41a1');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method POST is not supported for endpoint Channels"}',
            $content
        );

        // Try to POST with filter
        $response = $this->sendRequest('POST', 'channels?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method POST is not supported for endpoint Channels"}',
            $content
        );

        // Try to POST with identifier and filter
        $response = $this->sendRequest('POST', 'channels/0817d973-398e-41d7-9cd2-61cdb7ef41a1?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method POST is not supported for endpoint Channels"}',
            $content
        );

        // Try to PUT
        $response = $this->sendRequest('PUT', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method PUT is not supported for endpoint Channels"}',
            $content
        );

        // Try to DELETE
        $response = $this->sendRequest('DELETE', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Method DELETE is not supported for endpoint Channels"}',
            $content
        );
    }
}
