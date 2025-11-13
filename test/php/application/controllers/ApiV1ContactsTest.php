<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Tests\Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Test\BaseApiV1TestCase;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use WebSocket\Base;

class ApiV1ContactsTest extends BaseApiV1TestCase
{
    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts', ['full_name' => 'Test']);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
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
        self::deleteContacts($this->getConnection());

        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);

        // Create new contact
        self::createContacts($this->getConnection());

        // Now there are two
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts');
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResults([
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'username' => 'test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => ['email' => 'test@example.com']
            ],
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID_2,
                'full_name' => 'Test2',
                'username' => 'test2',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => ['email' => 'test@example.com']
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithAlreadyExistingIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithUnknownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNonMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts', ['full_name' => 'not_test']);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNonExistingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts', ['unknown' => 'filter']);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeError(
            'Invalid request parameter: Filter column unknown is not allowed'
        );
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithIdentifierAndFilter(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request: GET with identifier and query parameters, it\'s not allowed to use both together.'
        );

        // Valid identifier and valid filter
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            ['full_name' => 'Test']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            ['unknown' => 'filter']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
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
            'POST',
            $endpoint,
            'v1/contacts',
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
            'POST',
            $endpoint,
            'v1/contacts',
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
            'v1/contacts',
            ['id' => BaseApiV1TestCase::CONTACT_UUID],
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_4,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact not found'), $content);
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
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
    public function testPostToReplaceWithAlreadyExistingPayloadId(
        Connection $db,
        Url $endpoint
    ): void {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_2,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact already exists'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test (replaced)',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact created successfully'),
            $content
        );

        // Make sure the contact was replaced
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test (replaced)',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithExistingPayloadId(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact already exists'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact created successfully'),
            $content
        );

        // Let's see the contact is available at that location
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithMissingRequiredFields(
        Connection $db,
        Url $endpoint
    ): void {
        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field id must be present'),
            $content
        );

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field full_name must be present'),
            $content
        );

        // missing default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field default_channel must be present'),
            $content
        );

        // missing address type
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithInvalidFieldFormat(
        Connection $db,
        Url $endpoint
    ): void {
        // invalid id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => [BaseApiV1TestCase::CONTACT_UUID_3],
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects id to be of type string'),
            $content
        );

        // invalid name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => ['Test'],
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects full_name to be of type string'),
            $content
        );

        // invalid default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => [BaseApiV1TestCase::CHANNEL_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects default_channel to be of type string'),
            $content
        );

        // invalid addresses
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => 'test@example.com'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );

        // invalid username
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'username' => ['test']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects username to be of type string'),
            $content
        );

        // invalid groups
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'groups' => BaseApiV1TestCase::GROUP_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects groups to be of type array'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithValidOptionalData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact created successfully'),
            $content
        );

        // Oh really?
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
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
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithWebhookAsDefaultChannel(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test3',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID_2
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact created successfully'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidDefaultChannel(Connection $db, Url $endpoint): void
    {
        // invalid default_channel uuid
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => 'invalid_uuid',
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: given default_channel is not a valid UUID'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field id must be present'),
            $content
        );

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field full_name must be present'),
            $content
        );

        // missing default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field default_channel must be present'),
            $content
        );

        // missing address type
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidFieldFormat(
        Connection $db,
        Url $endpoint
    ): void {
        // invalid id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => [BaseApiV1TestCase::CONTACT_UUID_3],
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects id to be of type string'),
            $content
        );

        // invalid name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => ['Test'],
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects full_name to be of type string'),
            $content
        );

        // invalid default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => [BaseApiV1TestCase::CHANNEL_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects default_channel to be of type string'),
            $content
        );

        // invalid addresses
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => 'test@example.com'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );

        // invalid username
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'username' => ['test']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects username to be of type string'),
            $content
        );

        // invalid groups
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'groups' => BaseApiV1TestCase::GROUP_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects groups to be of type array'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidAddresses(Connection $db, Url $endpoint): void
    {
        // with invalid address type
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'invalid' => 'value'
                ]
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                "Invalid request body: an address according to default_channel type email is required"
            ),
            $content
        );

        // with invalid address type and matching address type
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'invalid' => 'value',
                    'email' => 'test@example.com'
                ]
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: undefined address type invalid given'),
            $content
        );

        // mismatch address type and default_channel type
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'webhook' => 'value'
                ]
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                "Invalid request body: an address according to default_channel type email is required"
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidOptionalData(Connection $db, Url $endpoint): void
    {
        // already existing username
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'username' => 'test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Username test already exists'), $content);

        // with non-existing group
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [BaseApiV1TestCase::GROUP_UUID_3],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Contact Group with identifier ' . BaseApiV1TestCase::GROUP_UUID_3 . ' does not exist'
            ),
            $content
        );

        // invalid group uuid
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => ['invalid_uuid'],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the group identifier invalid_uuid is not a valid UUID'),
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
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
            'v1/contacts?id=' . BaseApiV1TestCase::CONTACT_UUID_3,
            [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full-name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
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
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
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

        // missing id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field id must be present'),
            $content
        );

        // missing name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field full_name must be present'),
            $content
        );

        // missing default_channel
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field default_channel must be present'),
            $content
        );

        // missing address type
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithInvalidFieldFormat(
        Connection $db,
        Url $endpoint
    ): void {
        // invalid id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => [BaseApiV1TestCase::CONTACT_UUID],
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects id to be of type string'),
            $content
        );

        // invalid name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => ['Test'],
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects full_name to be of type string'),
            $content
        );

        // invalid default_channel
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => [BaseApiV1TestCase::CHANNEL_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects default_channel to be of type string'),
            $content
        );

        // invalid addresses
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => 'test@example.com'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );

        // invalid username
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'username' => ['test']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects username to be of type string'),
            $content
        );

        // invalid groups
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'groups' => BaseApiV1TestCase::GROUP_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects groups to be of type array'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithDifferentPayloadId(
        Connection $db,
        Url $endpoint
    ): void {
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'email' => 'test@example.com',
                    'webhook' => 'https://example.com/webhook',
                    'rocketchat' => 'https://chat.example.com/webhook',
                ]
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeSuccessMessage('Contact created successfully'),
            $content
        );

        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID_3,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
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
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);

        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithInvalidData(Connection $db, Url $endpoint): void
    {
        // invalid default_channel uuid
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID_3,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Channel with identifier ' . BaseApiV1TestCase::CHANNEL_UUID_3 . ' does not exist'
            ),
            $content
        );

        // invalid groups
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [BaseApiV1TestCase::GROUP_UUID_3],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Contact Group with identifier ' . BaseApiV1TestCase::GROUP_UUID_3 . ' does not exist'
            ),
            $content
        );

        // with invalid address type
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'invalid' => 'value'
                ]
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        // missing full_name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field full_name must be present'),
            $content
        );

        // missing id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field id must be present'),
            $content
        );

        // missing default_channel
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: the field default_channel must be present'),
            $content
        );

        // missing addresses
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();
        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithInvalidFieldFormat(
        Connection $db,
        Url $endpoint
    ): void {
        // invalid id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => [BaseApiV1TestCase::CONTACT_UUID_3],
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects id to be of type string'),
            $content
        );

        // invalid name
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => ['Test'],
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects full_name to be of type string'),
            $content
        );

        // invalid default_channel
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => [BaseApiV1TestCase::CHANNEL_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects default_channel to be of type string'),
            $content
        );

        // invalid addresses
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => 'test@example.com'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError(
                'Invalid request body: an address according to default_channel type email is required'
            ),
            $content
        );

        // invalid username
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'username' => ['test']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects username to be of type string'),
            $content
        );

        // invalid groups
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => ['email' => 'test@example.com'],
                'groups' => BaseApiV1TestCase::GROUP_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString(
            $this->jsonEncodeError('Invalid request body: expects groups to be of type array'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToChangeGroupMemberships(Connection $db, Url $endpoint): void
    {
        // First add a group to the user
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [BaseApiV1TestCase::GROUP_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Check the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);

        // Then remove it
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [],
                'addresses' => ['email' => 'test@example.com']
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Again, check the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);

        // And add it again
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [BaseApiV1TestCase::GROUP_UUID],
                'addresses' => ['email' => 'test@example.com']
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Then verify the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [BaseApiV1TestCase::GROUP_UUID],
            'addresses' => ['email' => 'test@example.com']
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToChangeAddresses(Connection $db, Url $endpoint): void
    {
        // First add addresses to the user
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'email' => 'test@example.com',
                    'webhook' => 'https://example.com/webhook',
                    'rocketchat' => 'https://chat.example.com/webhook',
                ]
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Check the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
        ]), $content);

        // Then remove one of them
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'email' => 'test@example.com',
                    'webhook' => 'https://example.com/webhook'
                ]
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Again check the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook'
            ]
        ]), $content);

        // And add it again
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'addresses' => [
                    'email' => 'test@example.com',
                    'webhook' => 'https://example.com/webhook',
                    'rocketchat' => 'https://chat.example.com/webhook',
                ]
            ]
        );

        $this->assertSame(204, $response->getStatusCode(), $response->getBody()->getContents());

        // Then verify the result
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeResult([
            'id' => BaseApiV1TestCase::CONTACT_UUID,
            'full_name' => 'Test',
            'username' => null,
            'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            'groups' => [],
            'addresses' => [
                'email' => 'test@example.com',
                'webhook' => 'https://example.com/webhook',
                'rocketchat' => 'https://chat.example.com/webhook',
            ]
        ]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithoutIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts');
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
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertJsonStringEqualsJsonString($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithKnownIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID);
        $content = $response->getBody()->getContents();

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts', ['name~*']);
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
        // General invalid method
        $response = $this->sendRequest('PATCH', $endpoint, 'v1/contacts');
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
