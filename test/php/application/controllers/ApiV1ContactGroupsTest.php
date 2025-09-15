<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

// TODO: partial updates with POST
class ApiV1ContactGroupsTest extends BaseApiV1TestCase
{
    /**
     * Get a specific contact group by providing a filter.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithMatchingFilter(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups?name=Test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get all contact groups currently stored at the endpoint.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $expected = $this->jsonEncode([]);

        $response = $this->sendRequest('GET', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // Create new contact groups
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test (2)',
            'users' => []
        ]);

        // Now there are two
        $expected = $this->jsonEncode([
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

        $response = $this->sendRequest('GET', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get a specific contact group by its identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithMatchingIdentifier(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Get a specific contact group by providing a non-existent identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testGetWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contactgroup not found');

        $response = $this->sendRequest('GET', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional GET tests
    /**
     * Get contact groups, while providing a non-matching name filter.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $expected = $this->jsonEncode([]);

        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('GET', 'contactgroups?name=not_test');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Invalid request body: given content is not a valid JSON');

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: 'contactgroups',
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

        $expected = $this->jsonEncode('Invalid request header: Content-Type must be application/json');

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: 'contactgroups',
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
     * Create a new contact group with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPostWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

        $response = $this->sendRequest(
            'POST',
            'contactgroups?id=' . BaseApiV1TestCase::GROUP_UUID,
            [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact group, while providing an unknown identifier.
     *
     * @dataProvider databases
     */
    public function testPostWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contactgroup not found');

        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode('Identifier mismatch: the Payload id must be different from the URL identifier');

        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
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
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode('Contactgroup already exists');

        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(409, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode('Contactgroup created successfully');
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID_2;

        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
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
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode('Contactgroup already exists');

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(409, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Contactgroup created successfully');
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID;

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => ['0817d973-398e-41d7-9ef2-61cdb7ef41a1']
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
    }

    // TODO: additional POST tests
    /**
     * Create a new contact group with an incorrect JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidData(): void
    {
        $expected = $this->jsonEncode(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing name
        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('POST', 'contactgroups', [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid users
        $expected = $this->jsonEncode(
            'User with identifier ' . BaseApiV1TestCase::CONTACT_UUID . ' not found'
        );

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing users
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID;

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
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
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('POST', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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

        $expected = $this->jsonEncode('Invalid request body: given content is not a valid JSON');

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID,
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

        $expected = $this->jsonEncode('Invalid request header: Content-Type must be application/json');

        $response = $this->sendRequest(
            method: 'PUT',
            endpoint: 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID,
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
     * Replace a contact group with a valid JSON payload, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testPutWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

        $response = $this->sendRequest('PUT', 'contactgroups?id=' . BaseApiV1TestCase::GROUP_UUID, [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact group, while omitting the identifier in the JSON payload.
     *
     * @dataProvider databases
     */
    public function testPutWithoutIdentifier(): void
    {
        $expected = $this->jsonEncode('Invalid request: Identifier is required');

        $response = $this->sendRequest('PUT', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        // TODO: should this be a 400 or 422?
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact group with the same identifier in the Request-URI
     * and the JSON payload, while omitting required fields.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndMissingRequiredFields(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = $this->jsonEncode('Identifier mismatch');

        // indifferent id
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => '0817d973-398e-41d7-9ef2-61cdb7ef41a3',
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact group with a valid JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $expected = $this->jsonEncode('Contactgroup created successfully');
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID;

        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
    }

    /**
     * Replace a contact group with the same identifier in the Request-URI and the JSON payload.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testPutWithMatchingIdentifierAndValidData(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $expected = '';

        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
        $expected = $this->jsonEncode('Identifier mismatch');

        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid users
        $expected = $this->jsonEncode(
            'User with identifier ' . BaseApiV1TestCase::CONTACT_UUID . ' not found'
        );
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact group with missing optional fields in the JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndValidOptionalData(): void
    {
        $expected = $this->jsonEncode('Contactgroup created successfully');
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID;
        // missing users
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
        $this->assertSame($expected, $content);
    }

    /**
     * Create a new contact group with missing required fields in the JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNonMatchingIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncode(
            'Invalid request body: the fields id and name must be present and of type string'
        );
        // missing name
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing id
        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Delete a contact group, while not providing an identifier in the Request-URI.
     *
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $expected = $this->jsonEncode('Invalid request: Identifier is required');

        $response = $this->sendRequest('DELETE', 'contactgroups');
        $content = $response->getBody()->getContents();

        // TODO: should this be a 400 or 422?
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Delete a contact group, while providing an identifier which doesn't exist.
     *
     * @dataProvider databases
     */
    public function testDeleteWithNonMatchingIdentifier(): void
    {
        $expected = $this->jsonEncode('Contactgroup not found');

        $response = $this->sendRequest('DELETE', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * Delete a contact group by its identifier.
     *
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testDeleteWithMatchingIdentifier(): void
    {
        $expected = '';
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('DELETE', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    //TODO: additional DELETE tests
    /**
     * Delete all contact groups, while providing a filter.
     *
     * @dataProvider databases
     */
    public function testDeleteWithFilter(): void
    {
        $expected = $this->jsonEncode('Unexpected query parameter: Filter is only allowed for GET requests');

        $response = $this->sendRequest('DELETE', 'contactgroups?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    // TODO: additional general tests
    /**
     * Send a request with a non-supported HTTP method.
     *
     * @dataProvider databases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        $expected = $this->jsonEncode('HTTP method PATCH is not supported');
        $expectedAllowHeader = 'GET, POST, PUT, DELETE';

        $response = $this->sendRequest('PATCH', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertSame($expected, $content);
    }
}
