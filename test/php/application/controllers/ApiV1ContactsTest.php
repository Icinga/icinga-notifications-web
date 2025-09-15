<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Exception\IcingaException;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

class ApiV1ContactsTest extends BaseApiV1TestCase
{
    /**
     * Get a specific contact by providing a filter.
     *
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

        $expected = $this->jsonEncode([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);

        $response = $this->sendRequest('GET', 'contacts?full_name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get all contacts currently stored at the endpoint.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $expected = $this->jsonEncode([]);

        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

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
        $expected = $this->jsonEncode([
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

        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get a specific contact by its identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithMatchingIdentifier(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $expected = $this->jsonEncode([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);

        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get a specific contact by providing a non-existent identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contact not found');

        $response = $this->sendRequest('GET', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional GET tests
    /**
     * Get contact, while providing a non-matching name filter.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $expected = $this->jsonEncode([]);

        $response = $this->sendRequest('GET', 'contacts?full_name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get contact, while providing a non-existing filter.
     *
     * @dataProvider databases
     */
    public function testGetWithNonExistingFilter(): void
    {
        $expected = $this->jsonEncode(
            'Invalid request parameter: Filter column unknown given, only id, full_name and username are allowed'
        );

        $response = $this->sendRequest('GET', 'contacts?unknown=filter');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get contact, while providing an identifier and a filter.
     *
     * @dataProvider databases
     */
    public function testGetWithIdentifierAndFilter(): void
    {
        $expected = $this->jsonEncode(
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

        $expected = $this->jsonEncode('Invalid request body: given content is not a valid JSON');

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
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Invalid request header: Content-Type must be application/json');

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
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPostWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

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
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact, while providing an unknown identifier.
     *
     * @dataProvider databases
     */
    public function testPostWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contact not found');

        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact with an id which is the same as the identifier. (is not a replacement)
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndIndifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $expected = $this->jsonEncode('Identifier mismatch: the Payload id must be different from the URL identifier');

        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact with an id which already exists
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndExistingPayloadId(): void
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
        $expected = $this->jsonEncode('Contact already exists');

        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(409, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact while providing a new identifier in the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $expected = $this->jsonEncode('Contact created successfully');
        $expectedLocation = 'notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_2;

        $response = $this->sendRequest('POST', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test (replaced)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Contact already exists');

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(409, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact with a valid JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithValidData(): void
    {
        $expected = $this->jsonEncode('Contact created successfully');
        $expectedLocation = 'notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID;

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
    }

    // TODO: additional POST tests
    /**
     * Replace a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncode('Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string');

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

        $expected = $this->jsonEncode('Contact created successfully');
        $expectedLocation = 'notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID;

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
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
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
        $expected = $this->jsonEncode('Invalid request body: given default_channel is not a valid UUID');

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => 'invalid_uuid',
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact with a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithMissingRequiredFields(): void
    {
        $expected = $this->jsonEncode('Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string');

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
        $expected = $this->jsonEncode('Username test already exists');

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(409, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // with non-existing group
        $expected = $this->jsonEncode(
            'Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID . ' does not exist'
        );

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid group uuid
        $expected = $this->jsonEncode('Invalid request body: the group identifier invalid_uuid is not a valid UUID');

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => ['invalid_uuid']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // with invalid address type
        $expected = $this->jsonEncode('Invalid request body: undefined address type invalid given');

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
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Invalid request body: given content is not a valid JSON');

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
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Invalid request header: Content-Type must be application/json');

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
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPutWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

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
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a missing identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithoutIdentifier(): void
    {
        $expected = $this->jsonEncode('Invalid request: Identifier is required');

        $response = $this->sendRequest('PUT', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        // TODO: should this be a 400 or 422?
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        // TODO: same results if the POST isn't done first
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        $expected = $this->jsonEncode('Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string');

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
    public function testPutWithMatchingIdentifierAndDifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        $expected = $this->jsonEncode('Identifier mismatch');

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact with a valid JSON payload with a new identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithNonMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $expected = $this->jsonEncode('Contact created successfully');
        $expectedLocation = 'notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_2;

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
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a valid identifier and JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $expected = '';

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional PUT tests
    /**
     * Update a contact with a non-matching identifier and invalid payload.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndInvalidData(): void
    {
        // different id
        $expected = $this->jsonEncode('Identifier mismatch');

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID_2,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid groups
        $expected = $this->jsonEncode('Contactgroup with identifier '
            . BaseApiV1TestCase::GROUP_UUID . ' does not exist');

        $response = $this->sendRequest('PUT', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID, [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Update a contact with a non-matching identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncode('Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string');

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
     * Delete a contact, while not providing an identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $expected = $this->jsonEncode('Invalid request: Identifier is required');

        $response = $this->sendRequest('DELETE', 'contacts');
        $content = $response->getBody()->getContents();

        // TODO: should this be a 400 or 422?
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Delete a contact, while providing an identifier which doesn't exist.
     *
     * @dataProvider databases
     */
    public function testDeleteWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contact not found');

        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Delete a contact by its identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testDeleteWithMatchingIdentifier(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $expected = '';

        $response = $this->sendRequest('DELETE', 'contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional DELETE tests
    /**
     * Delete all contacts, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testDeleteWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

        $response = $this->sendRequest('DELETE', 'contacts?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional general tests
    /**
     * Send a request with an invalid HTTP method.
     *
     * @dataProvider databases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        // General invalid method
        $expected = $this->jsonEncode('HTTP method PATCH is not supported');
        $expectedAllowHeader = 'GET, POST, PUT, DELETE';

        $response = $this->sendRequest('PATCH', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertSame($expected, $content);
    }
}
