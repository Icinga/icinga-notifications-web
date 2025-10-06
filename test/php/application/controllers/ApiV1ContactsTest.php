<?php

namespace Tests\Icinga\Module\Notifications\Controllers;

use GuzzleHttp\Client;
use Icinga\Exception\IcingaException;
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
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
        $this->assertSame($this->jsonEncodeResults([]), $content);

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
            'addresses' => []
        ]);
        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNewIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNonMatchingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts', ['full_name' => 'not_test']);
        $content = $response->getBody()->getContents();

        $this->assertSame(200, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeResults([]), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testGetWithNonExistingFilter(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('GET', $endpoint, 'v1/contacts', ['unknown' => 'filter']);
        $content = $response->getBody()->getContents();

        $expected = $this->jsonEncodeError(
            'Invalid request parameter: Filter column unknown given, only id, full_name and username are allowed'
        );
        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
        $this->assertSame($expected, $content);

        // Invalid identifier and invalid filter
        $response = $this->sendRequest(
            'GET',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            ['unknown' => 'filter']
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
        $this->assertSame($this->jsonEncodeError('Invalid request body: given content is not a valid JSON'), $content);
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
        $this->assertSame(
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
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithNewIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_4,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndIndifferentPayloadId(
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
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Identifier mismatch: the Payload id must be different from the URL identifier'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndExistingPayloadId(
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
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact already exists'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndValidData(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test (replaced)',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact already exists'), $content);
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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(201, $response->getStatusCode(), $content);
        $this->assertSame(
            ['notifications/api/v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3],
            $response->getHeader('Location')
        );
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithAlreadyExistingIdentifierAndMissingRequiredFields(
        Connection $db,
        Url $endpoint
    ): void {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID,
                'full_name' => 'Test'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToCreateWithInvalidData(Connection $db, Url $endpoint): void
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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(400, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: given default_channel is not a valid UUID'),
            $content
        );
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPostToReplaceWithMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

        // missing id
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing default_channel
        $response = $this->sendRequest(
            'POST',
            $endpoint,
            'v1/contacts',
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test'
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
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
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Username test already exists'), $content);

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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError(
                'Contactgroup with identifier ' . BaseApiV1TestCase::GROUP_UUID_3 . ' does not exist'
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
                'groups' => ['invalid_uuid']
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: the group identifier invalid_uuid is not a valid UUID'),
            $content
        );

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
        $this->assertSame(
            $this->jsonEncodeError('Invalid request body: undefined address type invalid given'),
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
        $this->assertSame($this->jsonEncodeError('Invalid request body: given content is not a valid JSON'), $content);
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
        $this->assertSame(
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
        $this->assertSame(
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
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndMissingRequiredFields(
        Connection $db,
        Url $endpoint
    ): void {
        // TODO: same results if identifier exists
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
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

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

        // missing name
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

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);

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

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndDifferentPayloadId(
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
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertSame(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToCreateWithNewIdentifierAndValidData(Connection $db, Url $endpoint): void
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
        $this->assertSame($this->jsonEncodeSuccessMessage('Contact created successfully'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithAlreadyExistingIdentifierAndValidData(Connection $db, Url $endpoint): void
    {
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

        $this->assertSame(204, $response->getStatusCode(), $content);
        $this->assertEmpty($content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithNewIdentifierAndInvalidData(Connection $db, Url $endpoint): void
    {
        // different id
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_4,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
            ]
        );
        $content = $response->getBody()->getContents();

        $this->assertEquals(422, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Identifier mismatch'), $content);

        // invalid groups
        $response = $this->sendRequest(
            'PUT',
            $endpoint,
            'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3,
            json:  [
                'id' => BaseApiV1TestCase::CONTACT_UUID_3,
                'full_name' => 'Test',
                'default_channel' => BaseApiV1TestCase::CHANNEL_UUID,
                'groups' => [BaseApiV1TestCase::GROUP_UUID_3],
            ]
        );
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
     * @dataProvider apiTestBackends
     */
    public function testPutToUpdateWithNewIdentifierAndMissingRequiredFields(Connection $db, Url $endpoint): void
    {
        $expected = $this->jsonEncodeError(
            'Invalid request body: the fields id, full_name and default_channel must be present and of type string'
        );

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
        $this->assertSame($expected, $content);

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
        $this->assertSame($expected, $content);

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
        $this->assertSame($expected, $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithoutIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts');
        $content = $response->getBody()->getContents();

        $this->assertSame(400, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Invalid request: Identifier is required'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithNewIdentifier(Connection $db, Url $endpoint): void
    {
        $response = $this->sendRequest('DELETE', $endpoint, 'v1/contacts/' . BaseApiV1TestCase::CONTACT_UUID_3);
        $content = $response->getBody()->getContents();

        $this->assertSame(404, $response->getStatusCode(), $content);
        $this->assertSame($this->jsonEncodeError('Contact not found'), $content);
    }

    /**
     * @dataProvider apiTestBackends
     */
    public function testDeleteWithAlreadyExistingIdentifier(Connection $db, Url $endpoint): void
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
        $this->assertSame(
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
        $this->assertSame($this->jsonEncodeError('HTTP method PATCH is not supported'), $content);
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
