<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

// TODO: partial updates with POST
class ApiV1ContactGroupsTest extends BaseApiV1TestCase
{
    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testGetWithMatchingFilter(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contact-groups?name=Test');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $response = $this->sendRequest('GET', 'contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);

        // Create new contact groups
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test (2)',
            'users' => []
        ]);

        // Now there are two
        $response = $this->sendRequest('GET', 'contact-groups');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ],
            [
                'id' => BaseApiV1TestCase::GROUP_UUID_2,
                'name' => 'Test (2)',
                'users' => []
            ]
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testGetWithAlreadyExistingIdentifier(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithNewIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contactgroup not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contact-groups?name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithInvalidContent(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: 'contact-groups',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request body: given content is not a valid JSON'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithInvalidContentType(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: 'contact-groups',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request header: Content-Type must be application/json'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithFilter(): void
    {
        $response = $this->sendRequest(
            'POST',
            'contact-groups?id=' . BaseApiV1TestCase::GROUP_UUID,
            [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToReplaceWithNonExistingIdentifier(): void
    {
        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contactgroup not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndIndifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Identifier mismatch: the Payload id must be different from the URL identifier'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndExistingPayloadId(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contactgroup already exists'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_2],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contactgroup created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPostToCreateWithAlreadyExistingPayloadId(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contactgroup already exists'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $response = $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contactgroup created successfully'), $content);

        // without optional field users
        $response = $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_2],
            $response->getHeader('Location')
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithInvalidData(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing name
        $response = $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('POST', 'contact-groups', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid users
        $response = $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('User with identifier ' . BaseApiV1TestCase::CONTACT_UUID . ' not found'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndInvalidData(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('POST', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithInvalidContent(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request body: given content is not a valid JSON'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithInvalidContentType(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request header: Content-Type must be application/json'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithFilter(): void
    {
        $response = $this->sendRequest('PUT', 'contact-groups?id=' . BaseApiV1TestCase::GROUP_UUID, [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithoutIdentifier(): void
    {
        $response = $this->sendRequest('PUT', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndMissingRequiredFields(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndDifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        // indifferent id
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToCreateWithNewIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contactgroup created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToCreateWithNewIdentifierAndInvalidData(): void
    {
        // different id
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);

        // invalid users
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('User with identifier ' . BaseApiV1TestCase::CONTACT_UUID . ' not found'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToCreateWithNewIdentifierAndValidOptionalData(): void
    {
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contactgroup created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToCreateWithNewIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );
        // missing name
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('PUT', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithNewIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contactgroup not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testDeleteWithAlreadyExistingIdentifier(): void
    {
        $this->sendRequest('POST', 'contact-groups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('DELETE', 'contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithFilter(): void
    {
        $response = $this->sendRequest('DELETE', 'contact-groups?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        $response = $this->sendRequest('PATCH', 'contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(['GET, POST, PUT, DELETE'], $response->getHeader('Allow'));
        $this->assertSame($this->jsonEncodeError('HTTP method PATCH is not supported'), $content);
    }

    public function tearDown(): void
    {
        foreach (self::sharedDatabases() as $driver => $connections) {
            $db = $connections[0];

            if ($driver === 'mysql' || $driver === 'pgsql') {
                $db->exec(<<<SQL
DELETE FROM contactgroup_member;
DELETE FROM contactgroup;
DELETE FROM contact;
SQL
                );
            }
        }
    }
}
