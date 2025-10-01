<?php

namespace Icinga\Module\Notifications\Test;

use DateTime;
use GuzzleHttp\Client;
use Icinga\Application\Config;
use Icinga\Application\Icinga;
use Icinga\Module\Notifications\Api\V1\Channels;
use Icinga\Util\Json;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use ipl\Sql\Test\SharedDatabases;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use PHPUnit\Framework\TestCase;

class BaseApiV1TestCase extends TestCase
{
    use SharedDatabases;

    protected const CHANNEL_UUID = '0817d973-398e-41d7-9cd2-61cdb7ef41a1';
    protected const CHANNEL_UUID_2 = '0817d973-398e-41d7-9cd2-61cdb7ef41a2';
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

    protected static function setUpSchema(Connection $db, string $driver): void
    {
        $webSchema = Icinga::app()->getBaseDir('schema') . "/$driver.schema.sql";

        $notificationSchemaPath = getenv('ICINGA_NOTIFICATIONS_SCHEMA');
        if (! $notificationSchemaPath) {
            throw new RuntimeException('Environment variable ICINGA_NOTIFICATIONS_SCHEMA is not set');
        }

        $notificationSchema = $notificationSchemaPath . "/$driver/schema.sql";
        if (! file_exists($notificationSchema)) {
            throw new RuntimeException("Schema file $notificationSchema does not exist");
        }

        if ($driver === 'pgsql') {
            $db->exec('CREATE SCHEMA icinga_web; SET search_path TO icinga_web');
        }

        $db->exec(file_get_contents($webSchema));
        self::createWebRows($db, $driver);

        if ($driver === 'pgsql') {
            $db->exec('CREATE SCHEMA icinga_notifications; SET search_path TO icinga_notifications');
        }

        $db->exec(file_get_contents($notificationSchema));

        self::createConfig($db, $driver);
    }

    protected static function tearDownSchema(Connection $db, string $driver): void
    {
        if ($driver === 'mysql') {
            $db->exec(<<<SQL
DROP PROCEDURE IF EXISTS DropTables;

CREATE PROCEDURE DropTables()
BEGIN
  DECLARE tlist TEXT;

  SET SESSION group_concat_max_len = 32768;
  SET FOREIGN_KEY_CHECKS = 0;

  SELECT GROUP_CONCAT(CONCAT('`', table_schema, '`.`', table_name, '`') SEPARATOR ',')
    INTO tlist
  FROM information_schema.tables
  WHERE table_schema = DATABASE();

  IF tlist IS NOT NULL THEN
    SET @tables = CONCAT('DROP TABLE IF EXISTS ', tlist);
    PREPARE stmt FROM @tables;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;

  SET FOREIGN_KEY_CHECKS = 1;
END;
SQL
            );
            $db->exec('CALL DropTables();');
        } elseif ($driver === 'pgsql') {
            $db->exec('DROP SCHEMA icinga_web CASCADE; DROP SCHEMA icinga_notifications CASCADE;');
        }
    }

    protected static function createWebRows(Connection $db, string $driver): void
    {
        $db->insert('icingaweb_user', [
            'name' => 'test',
            'active' => 1,
            'password_hash' => password_hash('test', PASSWORD_DEFAULT),
        ]);
    }

    protected static function createConfig(Connection $db, string $driver): void
    {
        Config::app()
            ->setSection('global', [
                'config_resource' => 'web_db'
            ])->setSection('logging', [
                'log' => 'php',
                'level' => 'debug'
            ])->saveIni();
        Config::app('resources')
            ->setSection('web_db', [
                'type' => 'db',
                'db' => $driver,
                'host' => $db->getConfig()->host,
                'port' => $db->getConfig()->port,
                'dbname' => $db->getConfig()->dbname,
                'username' => $db->getConfig()->username,
                'password' => $db->getConfig()->password
            ])->setSection('notifications_db_' . $driver, [
                'type' => 'db',
                'db' => $driver,
                'host' => $db->getConfig()->host,
                'port' => $db->getConfig()->port,
                'dbname' => $db->getConfig()->dbname,
                'username' => $db->getConfig()->username,
                'password' => $db->getConfig()->password
            ])->saveIni();
        Config::app('authentication')->setSection('test', [
            'backend' => 'db',
            'resource' => 'web_db'
        ])->saveIni();

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
        $db->delete('channel', 'external_uuid in ("' . self::CHANNEL_UUID . '", "' . self::CHANNEL_UUID_2 . '")');
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
        $db->delete('contact', 'external_uuid in ("' . self::CONTACT_UUID . '", "' . self::CONTACT_UUID_2 . '")');
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
        $db->delete('contactgroup', 'external_uuid in ("' . self::GROUP_UUID . '", "' . self::GROUP_UUID_2 . '")');
    }

    protected function sendRequest(
        string $method,
        string $endpoint,
        ?array $json = null,
        ?string $body = null,
        ?array $headers = null,
        ?array $options = null,
    ): ResponseInterface {
        $client = new Client();

        $options = $options ?? [
            'auth' => ['test', 'test'],
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

        return $client->request($method, 'http://127.0.0.1:1792/notifications/api/v1/' . $endpoint, $options);
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
        return Json::sanitize(['data' => [$data]]);
    }

    public function jsonEncodeResults(array $data): string
    {
        return Json::sanitize(['data' => (! empty($data) && ! isset($data[0])) ? [$data] : $data]);
    }
}
