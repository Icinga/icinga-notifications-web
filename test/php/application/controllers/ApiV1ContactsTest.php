<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;

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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('GET', 'contacts?full_name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","full_name":"Test","username":null,'
            . '"default_channel":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","groups":[],"addresses":[]}]}',
            $content
        );
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
        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame('{"content":[]}', $content);

        // Create new contact
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test (2)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        // Now there are two
        $response = $this->sendRequest('GET', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","full_name":"Test","username":null,'
            . '"default_channel":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","groups":[],"addresses":[]},' . PHP_EOL
            . '{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a3","full_name":"Test (2)","username":null,'
            . '"default_channel":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","groups":[],"addresses":[]}]}',
            $content
        );
    }

    /**
     * Get a specific contact  by its identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithMatchingIdentifier(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('GET', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","full_name":"Test","username":null,'
            . '"default_channel":"0817d973-398e-41d7-9cd2-61cdb7ef41a1","groups":[],"addresses":[]}',
            $content
        );
    }

    /**
     * Get a specific contact by providing a non-existent identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Contact not found"}', $content);
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
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contacts?full_name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame('{"content":[]}', $content);
    }

    /**
     * Get contact, while providing a non-existing filter.
     *
     * @dataProvider databases
     */
    public function testGetWithNonExistingFilter(): void
    {
        $response = $this->sendRequest('GET', 'contacts?unknown=filter');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request parameter: '
            . 'Filter column unknown given, only id, full_name and username are allowed"}',
            $content
        );
    }

    /**
     * Get contact, while providing an identifier and a filter.
     *
     * @dataProvider databases
     */
    public function testGetWithIdentifierAndFilter(): void
    {
        $expectedMessage = '{"status":"error","message":"Invalid request: '
            . 'GET with identifier and query parameters, it\'s not allowed to use both together."}';
        // Valid identifier and valid filter
        $response = $this->sendRequest('GET', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2?full_name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expectedMessage, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest('GET', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a22?unknown=filter');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expectedMessage, $content);
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
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: given content is not a valid JSON"}',
            $content
        );
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
            '{"status":"error","message":"Invalid request header: Content-Type must be application\/json"}',
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
            'contacts?id=0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request parameter: Filter is only allowed for GET requests"}',
            $content
        );
    }

    /**
     * Replace a contact, while providing an unknown identifier.
     *
     * @dataProvider databases
     */
    public function testPostWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contact not found"}',
            $content
        );
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contact already exists"}',
            $content
        );
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test (replaced)',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);
    }

    // TODO: send 409 instead of 422?
    /**
     * Create a new contact with an already existing id in payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithExistingId(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contact already exists"}',
            $content
        );
    }

    /**
     * Create a new contact with a valid JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);
    }

    // TODO: additional POST tests
    // TODO: send 422 instead of 400?
    /**
     * Replace a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        // missing id
        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing name
        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing default_channel
        $response = $this->sendRequest('POST', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );
    }

    /**
     * Create a new contact with a valid JSON payload with valid optional data.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithValidOptionalData(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a1',
            'name' => 'Test'
        ]);

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1'],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);

        // no username
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);

        // no groups
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a4',
            'full_name' => 'Test',
            'username' => 'test1',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a4',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);

        // no addresses
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a5',
            'full_name' => 'Test',
            'username' => 'test2',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a5',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contact created successfully"}', $content);
    }

    /**
     * Create a new contact with an incorrect JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithInvalidData(): void
    {
        // TODO: send 409 or 422?
        // already existing username
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);

        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a4',
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(409, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Username test already exists"}',
            $content
        );
    }

    // TODO: send 422 instead of 400?
    /**
     * Replace a contact with a missing required field.
     *
     * @dataProvider databases
     */
    public function testPostWithMissingRequiredFields(): void
    {
        // missing id
        $response = $this->sendRequest('POST', 'contacts', [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing name
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing default_channel
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );
    }

    /**
     * Create a new contact with a valid JSON payload with invalid optional data.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidOptionalData(): void
    {
        // with non-existing group
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1'],
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":'
            . '"Contactgroup with identifier 0817d973-398e-41d7-9ef2-61cdb7ef41a1 does not exist"}',
            $content
        );

        // invalid group uuid
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => 'invalid uuid',
            'groups' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":'
            . '"Invalid request body: given default_channel is not a valid UUID"}',
            $content
        );


        // TODO: send 422 instead of 400?
        // with invalid address type
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'addresses' => [
                'invalid' => 'value'
            ]
        ]);
        $content = $response->getBody()->getContents();
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: undefined address type invalid given"}',
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
            endpoint: 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: given content is not a valid JSON"}',
            $content
        );
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
            endpoint: 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request header: Content-Type must be application\/json"}',
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
            'contacts?id=0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
                'full-name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request parameter: Filter is only allowed for GET requests"}',
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request: Identifier is required"}',
            $content
        );
    }

    // TODO: send 422 instead of 400?
    /**
     * Update a contact with a valid identifier and a missing required field.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing name
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: ' .
            'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: ' .
            'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,

        ]);

        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Identifier mismatch"}',
            $content
        );
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a1',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
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
            '{"status":"success","message":"Contact created successfully"}',
            $content
        );
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame(
            '',
            $content
        );
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
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Identifier mismatch"}',
            $content
        );

        // invalid groups
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":'
            . '"Contactgroup with identifier 0817d973-398e-41d7-9ef2-61cdb7ef41a1 does not exist"}',
            $content
        );
    }

    // TODO: send 422 instead of 400?
    /**
     * Update a contact with a non-matching identifier and a missing required field.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndMissingRequiredFields(): void
    {
        // missing full_name
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );

        // missing default_channel
        $response = $this->sendRequest('PUT', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
        ]);
        $content = $response->getBody()->getContents();
        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id, full_name and default_channel must be present and of type string"}',
            $content
        );
    }

    /**
     * Delete a contact, while not providing an identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Invalid request: Identifier is required"}', $content);
    }

    /**
     * Delete a contact, while providing an identifier which doesn't exist.
     *
     * @dataProvider databases
     */
    public function testDeleteWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Contact not found"}', $content);
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
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $response = $this->sendRequest('DELETE', 'contacts/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame(
            '',
            $content
        );
    }

    // TODO: additional DELETE tests
    /**
     * Delete all contacts, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testDeleteWithFilter(): void
    {
        $response = $this->sendRequest('DELETE', 'contacts?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Invalid request parameter: ' .
            'Filter is only allowed for GET requests"}', $content);
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
        $response = $this->sendRequest('PATCH', 'contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"HTTP method PATCH is not supported"}',
            $content
        );
    }
}
