<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;

// TODO: partial updates with POST
class ApiV1ContactGroupsTest extends BaseApiV1TestCase
{
    /**
     * Get a specific contact group by providing a filter.
     *
     * @dataProvider databases
     */
    public function testGetWithMatchingFilter(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","name":"Test","users":[]}]}',
            $content
        );
    }

    /**
     * Get all contact groups currently stored at the endpoint.
     *
     * @dataProvider databases
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $response = $this->sendRequest('GET', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame('{"content":[]}', $content);

        // Create new contact groups
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a3', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'name' => 'Test (2)',
            'users' => []
        ]);

        // Now there are two
        $response = $this->sendRequest('GET', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"content":[{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","name":"Test","users":[]},' . PHP_EOL
            . '{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a3","name":"Test (2)","users":[]}]}',
            $content
        );
    }

    /**
     * Get a specific contact group by its identifier.
     *
     * @dataProvider databases
     */
    public function testGetWithMatchingIdentifier(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"id":"0817d973-398e-41d7-9ef2-61cdb7ef41a2","name":"Test","users":[]}',
            $content
        );
    }

    /**
     * Get a specific contact group by providing a non-existent identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('GET', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Contactgroup not found"}', $content);
    }

    // TODO: additional GET tests
    /**
     * Get contact groups, while providing a non-matching name filter.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups?name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame('{"content":[]}', $content);
    }

    /**
     * Create a new contact group with a YAML payload, while declaring the body type as application/json.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidContent(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $client = new Client();
        $response = $client->request('POST', 'http://127.0.0.1:1792/notifications/api/v1/contactgroups', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'http_errors' => false,
            'auth' => ['test', 'test'],
            'body' => $body
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: given content is not a valid JSON"}',
            $content
        );
    }

    /**
     * Create a new contact group with a YAML payload.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidContentType(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $client = new Client();
        $response = $client->request('POST', 'http://127.0.0.1:1792/notifications/api/v1/contactgroups', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ],
            'http_errors' => false,
            'auth' => ['test', 'test'],
            'body' => $body
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request header: Content-Type must be application\/json"}',
            $content
        );
    }

    /**
     * Create a new contact group with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPostWithFilter(): void
    {
        $response = $this->sendRequest(
            'POST',
            'contactgroups?id=0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
                'name' => 'Test',
                'users' => []
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
     * Replace a contact group, while providing an unknown identifier.
     *
     * @dataProvider databases
     */
    public function testPostWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('POST', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contactgroup not found"}',
            $content
        );
    }

    /**
     * Replace a contact group, while providing the same identifier in the Request-URI and the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndIndifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contactgroup already exists"}',
            $content
        );
    }

    /**
     * Replace a contact group while providing a new identifier in the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contactgroup created successfully"}', $content);
    }

    /**
     * Create a new contact group with a valid JSON payload, while providing an already existing Payload id.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithAlreadyExistingPayloadId(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Contactgroup already exists"}',
            $content
        );
    }

    /**
     * Create a new contact group with a valid JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a1',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertCount(1, $response->getHeader('Location'));
        $this->assertSame(
            'notifications/api/v1/contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            $response->getHeader('Location')[0]
        );
        $this->assertSame('{"status":"success","message":"Contactgroup created successfully"}', $content);
    }

    // TODO: additional POST tests
    /**
     * Create a new contact group with an incorrect JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidData(): void
    {
        // missing name
        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );

        // missing id
        $response = $this->sendRequest('POST', 'contactgroups', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );

        // invalid users
        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"User with identifier 0817d973-398e-41d7-9ef2-61cdb7ef41a1 not found"}',
            $content
        );

        // missing users
        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
    }

    /**
     * Replace a contact group with an incorrect JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPostWithMatchingIdentifierAndInvalidData(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        // missing id
        $response = $this->sendRequest('POST', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );

        // missing name
        $response = $this->sendRequest('POST', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );
    }

    /**
     * Replace a contact group with a YAML payload, while declaring the body type as application/json.
     *
     * @dataProvider databases
     */
    public function testPutWithInvalidContent(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $client = new Client();
        $response = $client->request(
            'PUT',
            'http://127.0.0.1:1792/notifications/api/v1/contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'http_errors' => false,
                'auth' => ['test', 'test'],
                'body' => $body
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
     * Replace a contact group with a YAML payload, while declaring the body type as application/json.
     *
     * @dataProvider databases
     */
    public function testPutWithInvalidContentType(): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $client = new Client();
        $response = $client->request(
            'PUT',
            'http://127.0.0.1:1792/notifications/api/v1/contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'text/yaml'
                ],
                'http_errors' => false,
                'auth' => ['test', 'test'],
                'body' => $body
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
     * Replace a contact group with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPutWithFilter(): void
    {
        $response = $this->sendRequest(
            'PUT',
            'contactgroups?id=0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            [
                'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
                'name' => 'Test',
                'users' => []
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
     * Replace a contact group, while omitting the identifier in the JSON payload.
     *
     * @dataProvider databases
     */
    public function testPutWithoutIdentifier(): void
    {
        $response = $this->sendRequest('PUT', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request: Identifier is required"}',
            $content
        );
    }

    /**
     * Replace a contact group with the same identifier in the Request-URI and the JSON payload, while omitting required fields.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        // missing id
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );

        // missing name
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: ' .
            'the fields id and name must be present and of type string"}',
            $content
        );
    }

    /**
     * Replace a contact group with a different identifier in the Request-URI and the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndDifferentPayloadId(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        // indifferent id
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Identifier mismatch"}',
            $content
        );
    }

    /**
     * Create a new contact group with a valid JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a1',
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"success","message":"Contactgroup created successfully"}',
            $content
        );
    }

    /**
     * Replace a contact group with the same identifier in the Request-URI and the JSON payload.
     *
     * @dataProvider databases
     */
    public function testPutWithMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test (replaced)',
            'users' => []
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
     * Create a new contact group with incorrect JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndInvalidData(): void
    {
        // different id
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Identifier mismatch"}',
            $content
        );

        // invalid users
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"User with identifier 0817d973-398e-41d7-9ef2-61cdb7ef41a1 not found"}',
            $content
        );
    }

    /**
     * Create a new contact group with missing optional fields in the JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndValidOptionalData(): void
    {
        // missing users
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
    }

    /**
     * Create a new contact group with missing required fields in the JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndMissingRequiredFields(): void
    {
        // missing name
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request body: '
            . 'the fields id and name must be present and of type string"}',
            $content
        );
    }

    /**
     * Delete a contact group, while not providing an identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Invalid request: Identifier is required"}', $content);
    }

    /**
     * Delete a contact group, while providing an identifier which doesn't exist.
     *
     * @dataProvider databases
     */
    public function testDeleteWithNonMatchingIdentifier(): void
    {
        $response = $this->sendRequest('DELETE', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame('{"status":"error","message":"Contactgroup not found"}', $content);
    }

    /**
     * Delete a contact group by its identifier.
     *
     * @dataProvider databases
     */
    public function testDeleteWithMatchingIdentifier(): void
    {
        $this->sendRequest('PUT', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2', [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a2',
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('DELETE', 'contactgroups/0817d973-398e-41d7-9ef2-61cdb7ef41a2');
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame(
            '',
            $content
        );
    }

    //TODO: additional DELETE tests
    /**
     * Delete all contact groups, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testDeleteWithFilter(): void
    {
        $response = $this->sendRequest('DELETE', 'contactgroups?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"Invalid request parameter: Filter is only allowed for GET requests"}',
            $content
        );
    }

    // TODO: additional general tests
    /**
     * Send a request with a non-supported HTTP method.
     *
     * @dataProvider databases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        $response = $this->sendRequest('PATCH', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(
            '{"status":"error","message":"HTTP method PATCH is not supported"}',
            $content
        );
    }
}
