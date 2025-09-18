<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use WebSocket\Base;

// TODO: partial updates with POST
class ApiV1ContactGroupsTest extends BaseApiV1TestCase
{
    /**
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

        $expected = $this->jsonEncodeResults([
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
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetEverything(): void
    {
        // At first, there are none
        $expected = $this->jsonEncodeResults([]);

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

        $response = $this->sendRequest('GET', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
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

        $expected = $this->jsonEncodeResult([
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
     * @dataProvider databases
     */
    public function testGetWithNewIdentifier(): void
    {
        $expected = $this->jsonEncodeError('Contactgroup not found');

        $response = $this->sendRequest('GET', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testGetWithNonMatchingFilter(): void
    {
        $expected = $this->jsonEncodeResults([]);

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

        $expected = $this->jsonEncodeError('Invalid request body: given content is not a valid JSON');

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

        $expected = $this->jsonEncodeError('Invalid request header: Content-Type must be application/json');

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
        $expected = $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests');

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
    public function testPostWithNewIdentifier(): void
    {
        $expected = $this->jsonEncodeError('Contactgroup not found');

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

        $expected = $this->jsonEncodeError(
            'Identifier mismatch: the Payload id must be different from the URL identifier'
        );

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

        $expected = $this->jsonEncodeError('Contactgroup already exists');

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

        $expected = $this->jsonEncodeSuccessMessage('Contactgroup created successfully');
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

        $expected = $this->jsonEncodeError('Contactgroup already exists');

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

        $expected = $this->jsonEncodeSuccessMessage('Contactgroup created successfully');
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

        // without optional field users
        $expectedLocation = 'notifications/api/v1/contactgroups/' . BaseApiV1TestCase::GROUP_UUID_2;

        $response = $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test'
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame([$expectedLocation], $response->getHeader('Location'));
    }

    /**
     * Create a new contact group with an incorrect JSON payload.
     *
     * @dataProvider databases
     */
    public function testPostWithInvalidData(): void
    {
        $expected = $this->jsonEncodeError(
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
        $expected = $this->jsonEncodeError(
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

        $expected = $this->jsonEncodeError(
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

        $expected = $this->jsonEncodeError('Invalid request body: given content is not a valid JSON');

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

        $expected = $this->jsonEncodeError('Invalid request header: Content-Type must be application/json');

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
        $expected = $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests');

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
        $expected = $this->jsonEncodeError('Invalid request: Identifier is required');

        $response = $this->sendRequest('PUT', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

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

        $expected = $this->jsonEncodeError(
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

        $expected = $this->jsonEncodeError('Identifier mismatch');

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
    public function testPutWithNewIdentifierAndValidData(): void
    {
        $response = $this->sendRequest('POST', 'contacts', [
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getBody()->getContents());

        $expected = $this->jsonEncodeSuccessMessage('Contactgroup created successfully');
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

        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * Create a new contact group with incorrect JSON payload, while providing a new identifier.
     *
     * @dataProvider databases
     */
    public function testPutWithNewIdentifierAndInvalidData(): void
    {
        // different id
        $expected = $this->jsonEncodeError('Identifier mismatch');

        $response = $this->sendRequest('PUT', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID, [
            'id' => BaseApiV1TestCase::GROUP_UUID_2,
            'name' => 'Test',
            'users' => []
        ]);
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // invalid users
        $expected = $this->jsonEncodeError(
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
    public function testPutWithNewIdentifierAndValidOptionalData(): void
    {
        $expected = $this->jsonEncodeSuccessMessage('Contactgroup created successfully');
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
    public function testPutWithNewIdentifierAndMissingRequiredFields(): void
    {
        $expected = $this->jsonEncodeError(
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
     * @dataProvider databases
     */
    public function testDeleteWithoutIdentifier(): void
    {
        $expected = $this->jsonEncodeError('Invalid request: Identifier is required');

        $response = $this->sendRequest('DELETE', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     */
    public function testDeleteWithNewIdentifier(): void
    {
        $expected = $this->jsonEncodeError('Contactgroup not found');

        $response = $this->sendRequest('DELETE', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     * @depends testPostWithValidData
     */
    public function testDeleteWithMatchingIdentifier(): void
    {
        $this->sendRequest('POST', 'contactgroups', [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);

        $response = $this->sendRequest('DELETE', 'contactgroups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider databases
     */
    public function testDeleteWithFilter(): void
    {
        $expected = $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests');

        $response = $this->sendRequest('DELETE', 'contactgroups?name~*');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider databases
     */
    public function testRequestWithNonSupportedMethod(): void
    {
        $expected = $this->jsonEncodeError('HTTP method PATCH is not supported');
        $expectedAllowHeader = 'GET, POST, PUT, DELETE';

        $response = $this->sendRequest('PATCH', 'contactgroups');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame([$expectedAllowHeader], $response->getHeader('Allow'));
        $this->assertSame($expected, $content);
    }
}
