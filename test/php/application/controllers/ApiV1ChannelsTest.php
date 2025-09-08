<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Test\BaseApiV1TestCase;

class ApiV1ChannelsTest extends BaseApiV1TestCase
{
    /**
     * Get all channels currently stored at the endpoint.
     *
     * @dataProvider databases
     */
    public function testGetEverything(): void
    {
//        // At first, there are none
//        $response = $this->sendRequest('GET', 'channels');
//        $content = $response->getBody()->getContents();
//
//        $this->assertSame(200, $response->getStatusCode(), $content);
//        $this->assertSame('{"content":[]}', $content);

        // Now there are two
        $response = $this->sendRequest('GET', 'channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","name":"Test","type":"email","config":null},'
            . "\n" . '{"id":"0817d973-398e-41d7-9cd2-61cdb7ef41a2","name":"Test2","type":"email","config":null}]}',
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
}
