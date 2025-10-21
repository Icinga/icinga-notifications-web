<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use WebSocket\Base;

class ApiV1ContactGroupsTest extends BaseApiV1TestCase
{
    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups', ['name' => 'Test']);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetEverything(Connection $db, Url $endpoint): void
    {
        // At first, there are none
        self::deleteContactGroups($this->getConnection());

        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);

        // Create new contact groups
        self::createContactGroups($this->getConnection());

        // Now there are two
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ],
            [
                'id' => BaseApiV1TestCase::GROUP_UUID_2,
                'name' => 'Test2',
                'users' => []
            ]
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithAlreadyExistingIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithUnknownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact Group not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNonMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups', ['name' => 'not_test']);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidContent(Connection $db, Url $endpoint): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: $endpoint,
            path: 'v1/contact-groups',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: given content is not a valid JSON'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidContentType(Connection $db, Url $endpoint): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            method: 'POST',
            endpoint: $endpoint,
            path: 'v1/contact-groups',
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request header: Content-Type must be application/json'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups?id=' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithUnknownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_4,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact Group not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithIndifferentPayloadId(
        Connection $db,
        Url $endpoint
    ): void {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Identifier mismatch: the Payload id must be different from the URL identifier'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithExistingPayloadId(
        Connection $db,
        Url $endpoint
    ): void {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_2,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact Group already exists'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test (replaced)',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact Group created successfully'),
            $content
        );

        // Make sure the contact group was replaced
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID_3,
            'name' => 'Test (replaced)',
            'users' => []
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithAlreadyExistingPayloadId(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test (replaced)',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact Group already exists'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact Group created successfully'),
            $content
        );

        // Let's see the contact group is available at that location
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID_3,
            'name' => 'Test',
            'users' => []
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithMissingRequiredFields(
        Connection $db,
        Url $endpoint
    ): void {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithValidOptionalData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID]
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact Group created successfully'),
            $content
        );

        // Oh really?
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID_3,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json:[
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidOptionalData(Connection $db, Url $endpoint): void
    {
        // invalid users
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID_3]
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('User with identifier ' . BaseApiV1TestCase::CONTACT_UUID_3 . ' not found'),
            $content
        );

        // invalid user id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contact-groups',
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test',
                'users' => ['invalid_uuid']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the user identifier invalid_uuid is not a valid UUID'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithInvalidContent(Connection $db, Url $endpoint): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: given content is not a valid JSON'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithInvalidContentType(Connection $db, Url $endpoint): void
    {
        $body = <<<YAML
---
payload: invalid
YAML;

        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            body: $body,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/yaml'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request header: Content-Type must be application/json'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups?id=' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                    'id' => BaseApiV1TestCase::GROUP_UUID,
                    'name' => 'Test',
                    'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithoutIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups',
            json: [
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request: Identifier is required'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithMissingRequiredFields(
        Connection $db,
        Url $endpoint
    ): void {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // missing name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithDifferentPayloadId(
        Connection $db,
        Url $endpoint
    ): void {
        // indifferent id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID_2,
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Identifier mismatch'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact Group created successfully'),
            $content
        );

        // Let's see the group is actually available
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID_3,
            'name' => 'Test',
            'users' => []
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test (replaced)',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);

        // Oh really?
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test (replaced)',
            'users' => []
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithInvalidData(Connection $db, Url $endpoint): void
    {
        // invalid users
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID_3]
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('User with identifier ' . BaseApiV1TestCase::CONTACT_UUID_3 . ' not found'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithValidOptionalData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID_3,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID]
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact Group created successfully'),
            $content
        );

        // Let's see the group is actually available
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID_3,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id and name must be present and of type string'
        );

        // missing name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // missing id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json:  [
                'name' => 'Test',
                'users' => []
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToChangeGroupMemberships(Connection $db, Url $endpoint): void
    {
        // First add a user to the group
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID]
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Check the result
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]), $content);

        // Then remove it
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => []
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Again, check the result
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => []
        ]), $content);

        // And add it again
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID,
            json: [
                'id' => BaseApiV1TestCase::GROUP_UUID,
                'name' => 'Test',
                'users' => [BaseApiV1TestCase::CONTACT_UUID]
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Then verify the final result
        $response = $this->sendRequest('GET', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::GROUP_UUID,
            'name' => 'Test',
            'users' => [BaseApiV1TestCase::CONTACT_UUID]
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithoutIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request: Identifier is required'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithUnknownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact Group not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithKnownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contact-groups/' . BaseApiV1TestCase::GROUP_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contact-groups', ['name~*']);
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Unexpected query parameter: Filter is only allowed for GET requests'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testRequestWithNonSupportedMethod(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('PATCH', $endpoint, 'v1/contact-groups');
        $content = $response->getBody()->getContents();

        $this->assertSame(405, $response->getStatusCode(), $content);
        $this->assertSame(['GET, POST, PUT, DELETE'], $response->getHeader('Allow'));
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('HTTP method PATCH is not supported'),
            $content
        );
    }

    public function setUp(): void
    {
        $db = $this->getConnection();

        $db->delete('contactgroup_member');
        $db->delete(
            'contact',
            "external_uuid NOT IN ('" . self::CONTACT_UUID . "', '" . self::CONTACT_UUID_2 . "')"
        );
        $db->delete('contactgroup');

        self::createContactGroups($db);
    }
}
