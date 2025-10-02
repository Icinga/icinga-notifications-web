<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Exception\IcingaException;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

class ApiV1ContactsTest extends BaseApiV1TestCase
{
    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithMatchingFilter(): void
    {
        $response = $this->sendRequest('GET', 'contacts?full_name=Test');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        self::deleteContacts($this->getConnection());

        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);

        // Create new contact
        self::createContacts($this->getConnection());

        // Now there are two
        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'username' => 'test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => []
            ],
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID_2,
                'full_name' => 'Test2',
                'username' => 'test2',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => []
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithAlreadyExistingIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithNewIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     * @depends testPostToCreateWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $response = $this->sendRequest('GET', 'contacts?full_name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithNonExistingFilter(): void
    {
        $response = $this->sendRequest('GET', 'contacts?unknown=filter');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeError(
            'Invalid request parameter: Filter column unknown given, only id, full_name and username are allowed'
        );
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testGetWithIdentifierAndFilter(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request: GET with identifier and query parameters, it\'s not allowed to use both together.'
        );

        // Valid identifier and valid filter
        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID . '?full_name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID . '?unknown=filter');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
            endpoint: 'contacts',
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
            endpoint: 'contacts',
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
            'contacts?id=' . BaseApiV1TestCase::CONTACT_UUID,
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
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
    public function testPostToReplaceWithNewIdentifier(): void
    {
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_4,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndIndifferentPayloadId(): void
    {
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
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
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndExistingPayloadId(): void
    {
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact already exists'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test (replaced)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithExistingPayloadId(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact already exists'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithValidOptionalData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test3',
            'username' => 'test3',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithInvalidData(): void
    {
        // invalid default_channel uuid
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => 'invalid_uuid',
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: given default_channel is not a valid UUID'),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToReplaceWithMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('POST', 'contacts', [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPostToCreateWithInvalidOptionalData(): void
    {
        // already existing username
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Username test already exists'), $content);

        // with non-existing group
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID_3],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError(
                'Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID_3 . ' does not exist'
            ),
            $content
        );

        // invalid group uuid
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => ['invalid_uuid']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: the group identifier invalid_uuid is not a valid UUID'),
            $content
        );

        // with invalid address type
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'addresses' => [
                'invalid' => 'value'
            ]
        ]);
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: undefined address type invalid given'),
            $content
        );
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
            endpoint: 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
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
            endpoint: 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
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
        $response = $this->sendRequest(
            'PUT',
            'contacts?id=' . BaseApiV1TestCase::CONTACT_UUID_3,
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full-name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
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
    public function testPutToUpdateWithoutIdentifier(): void
    {
        $response = $this->sendRequest('PUT', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndMissingRequiredFields(): void
    {
        // TODO: same results if identifier exists
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndDifferentPayloadId(): void
    {
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
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
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithNewIdentifierAndInvalidData(): void
    {
        // different id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_4,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);

        // invalid groups
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID_3],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError(
                'Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID_3 . ' does not exist'
            ),
            $content
        );
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testPutToUpdateWithNewIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing full_name
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
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
        $response = $this->sendRequest('DELETE', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithNewIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithAlreadyExistingIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider sharedDatabases
     */
    public function testDeleteWithFilter(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts?name~*');
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
        // General invalid method
        $response = $this->sendRequest('PATCH', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(['GET, POST, PUT, DELETE'], $response->getHeader('Allow'));
        $this->assertSame($this->jsonEncodeError('HTTP method PATCH is not supported'), $content);
    }

    public function tearDown(): void
    {
        $db = $this->getConnection();

        $db->delete('contact_address');
        $db->delete('contactgroup_member');
        $db->delete(
            'contactgroup',
            "external_uuid NOT IN ('" . self::GROUP_UUID . "', '" . self::GROUP_UUID_2 . "')"
        );
        $db->delete('contact');

        self::createContacts($db);
    }
}
