<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Tests\Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use WebSocket\Base;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiV1ChannelsTest extends BaseApiV1TestCase
{
    #[DataProvider('apiTestBackends')]
    public function testGetWithMatchingFilter(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeResults([
            'id'     => BaseApiV1TestCase::CHANNEL_UUID,
            'name'   => 'Test',
            'type'   => 'email',
            'config' => null,
        ]);

        // filter by id
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels',
            ['id' => BaseApiV1TestCase::CHANNEL_UUID]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // filter by name
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels',
            ['name' => 'Test']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // filter by type
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels',
            ['type' => 'email']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // filter by all available filters together
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels',
            ['id' => BaseApiV1TestCase::CHANNEL_UUID, 'name' => 'Test', 'type' => 'email']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetEverything(Connection $db, Url $endpoint): void
    {
        // At first, there are none
        $this->deleteDefaultEntities();

        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels'
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);

        // Create new contact groups
        $this->createDefaultEntities();

        // There are two
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels'
        );
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id'     => BaseApiV1TestCase::CHANNEL_UUID,
                'name'   => 'Test',
                'type'   => 'email',
                'config' => null,
            ],
            [
                'id'     => BaseApiV1TestCase::CHANNEL_UUID_2,
                'name'   => 'Test2',
                'type'   => 'webhook',
                'config' => null,
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithAlreadyExistingIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id'     => BaseApiV1TestCase::CHANNEL_UUID,
            'name'   => 'Test',
            'type'   => 'email',
            'config' => null,
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithNonMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/channels', ['name' => 'not_test']);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithInvalidFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/channels', ['nonexistingfilter' => 'value']);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeError(
            'Invalid request parameter: Filter column nonexistingfilter is not allowed',
        );
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithNewIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Channel not found'), $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithInvalidIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/channels/' . BaseApiV1TestCase::UUID_INCOMPLETE);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('The given identifier is not a valid UUID'),
            $content
        );
    }

    #[DataProvider('apiTestBackends')]
    public function testGetWithIdentifierAndFilter(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request: GET with identifier and query parameters, it\'s not allowed to use both together.',
        );

        // Valid identifier and valid filter
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID,
            ['name' => 'Test']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID,
            ['nonexistingfilter' => 'value']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    #[DataProvider('apiTestBackends')]
    public function testRequestWithNonSupportedMethod(Connection $db, Url $endpoint): void
    {
        $expectedAllowHeader = 'GET';
        // General invalid method
        $response = $this->sendRequest('PATCH', $endpoint, 'v1/channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('HTTP method PATCH is not supported'),
            $content
        );

        // Endpoint specific invalid method
        // Try to POST
        $expected = $this->jsonEncodeError('Method POST is not supported for endpoint channels');
        //Try to POST without identifier
        $response = $this->sendRequest('POST', $endpoint, 'v1/channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Try to POST with identifier
        $response = $this->sendRequest('POST', $endpoint, 'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Try to POST with filter
        $response = $this->sendRequest('POST', $endpoint, 'v1/channels', ['name' => 'Test']);
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Try to POST with identifier and filter
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/channels/' . BaseApiV1TestCase::CHANNEL_UUID,
            ['name' => 'Test']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Try to PUT
        $response = $this->sendRequest('PUT', $endpoint, 'v1/channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Method PUT is not supported for endpoint channels'),
            $content
        );

        // Try to DELETE
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/channels');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Method DELETE is not supported for endpoint channels'),
            $content
        );
    }

    protected function deleteDefaultEntities(): void
    {
        $db = $this->getConnection();

        self::deleteContacts($db);
        self::deleteChannels($db);
    }

    protected function createDefaultEntities(): void
    {
        $db = $this->getConnection();

        self::createChannels($db);
        self::createContacts($db);
    }
}
