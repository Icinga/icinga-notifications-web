<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Exception\IcingaException;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

class ApiV1ContactsTest extends BaseApiV1TestCase
{
    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithMatchingFilter(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('GET', 'contacts?full_name=Test');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);

        // Create new contact
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test (2)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        // Now there are two
        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'username' => null,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => []
            ],
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID_2,
                'full_name' => 'Test (2)',
                'username' => null,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => []
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithAlreadyExistingIdentifier(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     */
    public function testGetWithNewIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $response = $this->sendRequest('GET', 'contacts?full_name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider databases
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
     * @dataProvider databases
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
     * Create a new contact with a YAML payload, while declaring the body type as application/json.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidContent(): void
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
     * Create a new contact with a YAML payload.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidContentType(): void
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
     * Create a new contact with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPostWithFilter(): void
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
     * Replace a contact, while providing an unknown identifier.
     *
     * @dataProvider databases
     */
    public function testPostWithNewIdentifier(): void
    {
        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * Replace a contact with an id which is the same as the identifier. (is not a replacement)
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithAlreadyExistingIdentifierAndIndifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

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
     * Replace a contact with an id which already exists
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithAlreadyExistingIdentifierAndExistingPayloadId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
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
     * Replace a contact while providing a new identifier in the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithAlreadyExistingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test (replaced)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_2],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * Create a new contact with an already existing id in payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithExistingId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

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
     * Create a new contact with a valid JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * Replace a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithAlreadyExistingIdentifierAndMissingRequiredFields(): void
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
     * Create a new contact with a valid JSON payload with valid optional data.
     *
     * @dataProvider databases
     */
    public function testPostWithValidOptionalData(): void
    {
        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test'
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
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
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * Create a new contact with an incorrect JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithInvalidData(): void
    {
        // invalid default_channel uuid
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
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
     * Replace a contact with a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithMissingRequiredFields(): void
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
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact with a valid JSON payload with invalid optional data.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidOptionalData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        // already existing username
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Username test already exists'), $content);

        // with non-existing group
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID . ' does not exist'),
            $content
        );

        // invalid group uuid
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
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
            'id' => BaseApiV1TestCase::CONTACT_UUID,
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
     * Update a contact with an invalid JSON payload, while declaring the body type as application/json.
     *
     * @dataProvider databases
     */
    public function testPutWithInvalidContent(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contacts/' . BaseApiV1TestCase::CONTACT_UUID,
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
     * Update a contact with a YAML payload.
     *
     * @dataProvider databases
     */
    public function testPutWithInvalidContentType(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contacts/' . BaseApiV1TestCase::CONTACT_UUID,
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
     * Update a contact with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPutWithFilter(): void
    {
        $response = $this->sendRequest(
            'PUT',
            'contacts?id=' . BaseApiV1TestCase::CONTACT_UUID,
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
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
     * Update a contact with a missing identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithoutIdentifier(): void
    {
        $response = $this->sendRequest('PUT', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * Update a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithAlreadyExistingIdentifierAndMissingRequiredFields(): void
    {
        // TODO: same results if the POST isn't done first
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a different identifier and payload id.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithAlreadyExistingIdentifierAndDifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);
    }

    /**
     * Create a new contact with a valid JSON payload with a new identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithNewIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID_2, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
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
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_2],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * Update a contact with a valid identifier and JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithAlreadyExistingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

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
     * Update a contact with a non-matching identifier and invalid payload.
     *
     * @dataProvider databases
     */
    public function testPutWithNewIdentifierAndInvalidData(): void
    {
        // different id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);

        // invalid groups
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID . ' does not exist'),
            $content
        );
    }

    /**
     * Update a contact with a non-matching identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPutWithNewIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing full_name
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
        ]);
        $content = $response->getBody()->getContents();
        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider databases
     */
    public function testDeleteWithNewIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testDeleteWithAlreadyExistingIdentifier(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider databases
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
     * @dataProvider databases
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
}
