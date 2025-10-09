<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Test;

use DateTime;
use GuzzleHttp\Client;
use Icinga\Module\Notifications\Api\V1\Channels;
use Icinga\Util\Json;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

class BaseApiV1TestCase extends TestCase
{
    use ApiTestBackends;

    protected const CHANNEL_UUID = '0817d973-398e-41d7-9cd2-61cdb7ef41a1';
    protected const CHANNEL_UUID_2 = '0817d973-398e-41d7-9cd2-61cdb7ef41a2';
    protected const CHANNEL_UUID_3 = '0817d973-398e-41d7-9cd2-61cdb7ef41a3';
    protected const CONTACT_UUID = '1817d973-398e-41d7-9cd2-61cdb7ef41a1';
    protected const CONTACT_UUID_2 = '1817d973-398e-41d7-9cd2-61cdb7ef41a2';
    protected const CONTACT_UUID_3 = '1817d973-398e-41d7-9cd2-61cdb7ef41a3';
    protected const CONTACT_UUID_4 = '1817d973-398e-41d7-9cd2-61cdb7ef41a4';
    protected const GROUP_UUID = '2817d973-398e-41d7-9cd2-61cdb7ef41a1';
    protected const GROUP_UUID_2 = '2817d973-398e-41d7-9cd2-61cdb7ef41a2';
    protected const GROUP_UUID_3 = '2817d973-398e-41d7-9cd2-61cdb7ef41a3';
    protected const GROUP_UUID_4 = '2817d973-398e-41d7-9cd2-61cdb7ef41a4';
    /**
     * @var string UUID template, add 2 chars [0-9|a-f] to create a valid UUID
     */
    protected const UUID_INCOMPLETE = '3817d973-398e-41d7-9cd2-61cdb7ef41';

    protected static function initializeNotificationsDb(Connection $db, string $driver): void
    {
        $db->insert('available_channel_type', [
                'type' => 'email',
                'name' => 'Email',
                'version' => 1,
                'author' => 'Test',
                'config_attrs' => ''
            ]);
        $db->insert('available_channel_type', [
                'type' => 'webhook',
                'name' => 'Webhook',
                'version' => 1,
                'author' => 'Test',
                'config_attrs' => ''
        ]);
        $db->insert('available_channel_type', [
            'type' => 'rocketchat',
            'name' => 'rocketchat',
            'version' => 1,
            'author' => 'Test',
            'config_attrs' => ''
        ]);

        self::createChannels($db);
        self::createContacts($db);
        self::createContactGroups($db);
    }

    protected static function createChannels(Connection $db): void
    {
        $db->insert('channel', [
            'external_uuid' => self::CHANNEL_UUID,
            'name' => 'Test',
            'type' => 'email',
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);

        $db->insert('channel', [
            'external_uuid' => self::CHANNEL_UUID_2,
            'name' => 'Test2',
            'type' => 'webhook',
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
    }

    protected static function deleteChannels(Connection $db): void
    {
        $db->delete('channel', "external_uuid in ('" . self::CHANNEL_UUID . "', '" . self::CHANNEL_UUID_2 . "')");
    }

    protected static function createContacts(Connection $db): void
    {
        $channelId = $db->select(
            (new Select())
                ->from('channel')
                ->columns(['id'])
                ->where('external_uuid = ?', self::CHANNEL_UUID)
                ->limit(1)
        )->fetchColumn();

        $db->insert('contact', [
            'full_name' => 'Test',
            'username' => 'test',
            'default_channel_id' => $channelId,
            'external_uuid' => self::CONTACT_UUID,
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
        $db->insert('contact', [
            'full_name' => 'Test2',
            'username' => 'test2',
            'default_channel_id' => $channelId,
            'external_uuid' => self::CONTACT_UUID_2,
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
    }

    protected static function deleteContacts(Connection $db): void
    {
        $db->delete('contact', "external_uuid in ('" . self::CONTACT_UUID . "', '" . self::CONTACT_UUID_2 . "')");
    }

    protected static function createContactGroups(Connection $db): void
    {
        $db->insert('contactgroup', [
            'name' => 'Test',
            'external_uuid' => self::GROUP_UUID,
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
        $db->insert('contactgroup', [
            'name' => 'Test2',
            'external_uuid' => self::GROUP_UUID_2,
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
    }

    protected static function deleteContactGroups(Connection $db): void
    {
        $db->delete('contactgroup', "external_uuid in ('" . self::GROUP_UUID . "', '" . self::GROUP_UUID_2 . "')");
    }

    protected function sendRequest(
        string $method,
        Url $endpoint,
        string $path,
        array $params = [],
        ?array $json = null,
        ?string $body = null,
        ?array $headers = null,
        ?array $options = null,
    ): ResponseInterface {
        $client = new Client();

        $url = $endpoint->setPath($path)->setParams($params)->getAbsoluteUrl();

        $options = $options ?? [
            'http_errors' => false
        ];
        $headers = $headers ?? ['Accept' => 'application/json'];

        if (! empty($headers)) {
            $options['headers'] = $headers;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($body !== null) {
            $options['body'] = $body;
        }

        return $client->request($method, $url, $options);
    }

    public function jsonEncodeError(string $message): string
    {
        return Json::sanitize(['message' => $message]);
    }

    public function jsonEncodeSuccessMessage(string $message): string
    {
        return Json::sanitize(['message' => $message]);
    }

    public function jsonEncodeResult(array $data): string
    {
        return Json::sanitize($data);
    }

    public function jsonEncodeResults(array $data): string
    {
        $needsWrapping = ! array_is_list($data) || count(array_filter($data, 'is_array')) !== count($data);
        return Json::sanitize(['data' => $needsWrapping ? [$data] : $data]);
    }
}
