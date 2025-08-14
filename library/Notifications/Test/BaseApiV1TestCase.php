<?php

namespace Icinga\Module\Notifications\Test;

use DateTime;
use GuzzleHttp\Client;
use Icinga\Application\Config;
use Icinga\Application\Icinga;
use Icinga\Test\BaseTestCase;
use ipl\Sql\Connection;
use ipl\Sql\Test\Databases;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class BaseApiV1TestCase extends BaseTestCase
{
    use Databases;

    protected const CHANNEL_UUID = '0817d973-398e-41d7-9cd2-61cdb7ef41a1';

    protected function createSchema(Connection $db, string $driver): void
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
        $this->createWebRows($db, $driver);

        if ($driver === 'pgsql') {
            $db->exec('CREATE SCHEMA icinga_notifications; SET search_path TO icinga_notifications');
        }

        $db->exec(file_get_contents($notificationSchema));

        $this->createConfig($db, $driver);
    }

    protected function dropSchema(Connection $db, string $driver): void
    {
        if ($driver === 'mysql') {
            $db->exec(<<<SQL
SET FOREIGN_KEY_CHECKS = 0; 
SET @tables = NULL;
SET GROUP_CONCAT_MAX_LEN=32768;

SELECT GROUP_CONCAT('`', table_schema, '`.`', table_name, '`') INTO @tables
FROM   information_schema.tables 
WHERE  table_schema = (SELECT DATABASE());
SELECT IFNULL(@tables, '') INTO @tables;

SET        @tables = CONCAT('DROP TABLE IF EXISTS ', @tables);
PREPARE    stmt FROM @tables;
EXECUTE    stmt;
DEALLOCATE PREPARE stmt;
SET        FOREIGN_KEY_CHECKS = 1;
SQL
            );
        } elseif ($driver === 'pgsql') {
            $db->exec('DROP SCHEMA icinga_web CASCADE; DROP SCHEMA icinga_notifications CASCADE;');
        }
    }

    protected function createWebRows(Connection $db, string $driver): void
    {
        $db->insert('icingaweb_user', [
            'name' => 'test',
            'active' => 1,
            'password_hash' => password_hash('test', PASSWORD_DEFAULT),
        ]);
    }

    protected function createConfig(Connection $db, string $driver): void
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
            ])->setSection('notifications_db', [
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
        Config::module('notifications')->setSection('database', [
            'resource' => 'notifications_db'
        ])->saveIni();

        $db->insert('available_channel_type', [
            'type' => 'email',
            'name' => 'Email',
            'version' => 1,
            'author' => 'Test',
            'config_attrs' => ''
        ]);

        $db->insert('channel', [
            'external_uuid' => self::CHANNEL_UUID,
            'name' => 'Test',
            'type' => 'email',
            'changed_at' => (int) (new DateTime())->format("Uv"),
        ]);
    }

    protected function sendRequest(string $method, string $endpoint, ?array $json = null): ResponseInterface
    {
        $client = new Client();

        $options = [
            'headers' => ['Accept' => 'application/json'],
            'auth' => ['test', 'test'],
            'http_errors' => false
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $client->request($method, 'http://127.0.0.1:1792/notifications/api/v1/' . $endpoint, $options);
    }
}
