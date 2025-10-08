<?php

namespace Icinga\Module\Notifications\Test;

use Icinga\Application\Config;
use Icinga\Web\Request;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases;
use RuntimeException;

/**
 * Data provider for API tests
 *
 * To use it, implement {@see ApiTestBackends::initializeNotificationsDb()}. The environment also needs to provide
 * the following variables: (Replace * with the name of a supported database adapter)
 *
 *  Name                   | Description
 *  ---------------------- | ----------------------------------------------------------------
 *  *_ICINGAWEBDB          | The Icinga Web database to use
 *  *_ICINGAWEBDB_USER     | The user to connect with the Icinga Web database
 *  *_ICINGAWEBDB_PASSWORD | The password of the user to connect with the Icinga Web database
 *
 * The data provider will then provide the following parameters to each test:
 *  - 0: {@see Connection}, The database connection to use for the test
 *  - 1: {@see Url}, The endpoint to use for the test, just set a path and params and you're good to go
 *
 * @internal This trait is only intended for use by the Icinga\Module\Notifications\Test\ApiV*TestCase classes
 */
trait ApiTestBackends
{
    use SharedDatabases;

    private const MYSQL_PROCEDURE_CALL = 'CALL DropEverything();';

    private const MYSQL_DROP_PROCEDURE = <<<SQL
DROP PROCEDURE IF EXISTS DropEverything;

CREATE PROCEDURE DropEverything()
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
SQL;

    private const PGSQL_DROP_PROCEDURE = <<<SQL
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
SQL;

    /**
     * All backend endpoints
     *
     * @internal Only the trait itself should access this property
     *
     * @var array<string, array{0: Connection, 1: Url}>
     */
    private static array $backends = [];

    /**
     * Initialize the configuration for the API tests
     *
     * @param Connection $db
     * @param string $driver
     *
     * @return void
     */
    abstract protected static function initializeNotificationsDb(Connection $db, string $driver): void;

    /**
     * Provide the endpoints for the API tests plus their accompanying database connections
     *
     * @return array<string, array{0: Connection, 1: Url}>
     */
    final public function apiTestBackends(): array
    {
        self::initializeBackends();

        return self::$backends;
    }

    /**
     * Initialize the API test backends
     *
     * @return void
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function initializeBackends(): void
    {
        $webPath = self::getIcingaWebPath();

        $port = 1792;
        foreach (self::sharedDatabases() as $name => $connection) {
            if (isset(self::$backends[$name])) {
                continue;
            }

            $socket = sprintf('127.0.0.1:%d', $port);
            $configDir = sys_get_temp_dir() . "/notifications-api-test-backend-$port";

            self::initializeIcingaWeb($name, $configDir);

            if (self::fork()) {
                pcntl_exec(
                    readlink('/proc/self/exe'),
                    ['-q', '-S', $socket, '-t', "$webPath/public", "$webPath/public/index.php"],
                    [
                        'ICINGAWEB_CONFIGDIR' => $configDir,
                        'ICINGAWEB_LIBDIR' => self::getEnvironmentVariable('ICINGAWEB_LIBDIR')
                    ]
                );
            } else {
                self::$backends[$name] = [
                    $connection[0],
                    Url::fromRequest(request: new Request())
                        ->setScheme('http')
                        ->setHost('127.0.0.1')
                        ->setPort($port)
                        ->setBasePath('/notifications/api')
                        ->setUsername('test')
                        ->setPassword('test')
                ];
            }

            $port++;
        }
    }

    /**
     * Initialize the Icinga Web configuration
     *
     * @param string $driver
     * @param string $configDir
     *
     * @return void
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function initializeIcingaWeb(string $driver, string $configDir): void
    {
        $oldConfigDir = Config::$configDir;
        Config::$configDir = $configDir;

        $connectionConfig = self::getConnectionConfig($driver);

        Config::app(fromDisk: true)
            ->setSection('global', [
                'config_resource' => 'web_db'
            ])->setSection('logging', [
                'log' => 'php',
                'level' => 'debug'
            ])->saveIni();
        Config::app('resources', true)
            ->setSection('web_db', [
                'type' => 'db',
                'db' => $connectionConfig->db,
                'host' => $connectionConfig->host,
                'port' => $connectionConfig->port,
                'dbname' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB'),
                'username' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB_USER'),
                'password' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB_PASSWORD')
            ])->setSection('notifications_db', [
                'type' => 'db',
                'db' => $connectionConfig->db,
                'host' => $connectionConfig->host,
                'port' => $connectionConfig->port,
                'dbname' => $connectionConfig->dbname,
                'username' => $connectionConfig->username,
                'password' => $connectionConfig->password
            ])->saveIni();
        Config::app('roles', true)->setSection('test', [
            'permissions' => 'module/notifications,notifications/api',
            'users' => 'test'
        ])->saveIni();
        Config::app('authentication', true)->setSection('test', [
            'backend' => 'db',
            'resource' => 'web_db'
        ])->saveIni();
        Config::module('notifications', fromDisk: true)->setSection('database', [
            'resource' => 'notifications_db'
        ])->saveIni();

        Config::$configDir = $oldConfigDir;

        if (! is_link("$configDir/enabledModules/notifications")) {
            mkdir("$configDir/enabledModules", 0755, true);
            symlink(realpath(__DIR__ . '/../../..'), "$configDir/enabledModules/notifications");
        }
    }

    final protected static function setUpSchema(Connection $db, string $driver): void
    {
        $webSchema = self::getIcingaWebPath() . "/schema/$driver.schema.sql";

        $notificationSchemaPath = getenv('ICINGA_NOTIFICATIONS_SCHEMA');
        if (! $notificationSchemaPath) {
            throw new RuntimeException('Environment variable ICINGA_NOTIFICATIONS_SCHEMA is not set');
        }

        $notificationSchema = $notificationSchemaPath . "/$driver/schema.sql";
        if (! file_exists($notificationSchema)) {
            throw new RuntimeException("Schema file $notificationSchema does not exist");
        }

        $webDb = self::connectToIcingaWebDb($driver);
        $webDb->exec(file_get_contents($webSchema));
        self::initializeIcingaWebDb($webDb, $driver);

        $db->exec(file_get_contents($notificationSchema));
        static::initializeNotificationsDb($db, $driver);
    }

    final protected static function tearDownSchema(Connection $db, string $driver): void
    {
        $webDb = self::connectToIcingaWebDb($driver);

        if ($driver === 'mysql') {
            $webDb->exec(self::MYSQL_DROP_PROCEDURE);
            $db->exec(self::MYSQL_DROP_PROCEDURE);

            $webDb->exec(self::MYSQL_PROCEDURE_CALL);
            $db->exec(self::MYSQL_PROCEDURE_CALL);
        } elseif ($driver === 'pgsql') {
            $webDb->exec(self::PGSQL_DROP_PROCEDURE);
            $db->exec(self::PGSQL_DROP_PROCEDURE);
        }
    }

    /**
     * Initialize the Icinga Web database
     *
     * @param Connection $db
     * @param string $driver
     *
     * @return void
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function initializeIcingaWebDb(Connection $db, string $driver): void
    {
        $db->insert('icingaweb_user', [
            'name' => 'test',
            'active' => 1,
            'password_hash' => password_hash('test', PASSWORD_DEFAULT),
        ]);
    }

    /**
     * Get the path to the Icinga Web installation
     *
     * @return string
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function getIcingaWebPath(): string
    {
        $webPath = getenv('ICINGAWEB_PATH');
        if ($webPath === false) {
            echo "ICINGAWEB_PATH environment variable not set\n";
            exit(1);
        }

        $webPath = realpath($webPath);
        if (! $webPath) {
            echo "ICINGAWEB_PATH environment variable is not a valid path: $webPath\n";
            exit(1);
        }

        return $webPath;
    }

    /**
     * Connect to the Icinga Web database
     *
     * @param string $driver
     *
     * @return Connection
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function connectToIcingaWebDb(string $driver): Connection
    {
        return new Connection([
            'db' => $driver,
            'host' => self::getEnvironmentVariable(strtoupper($driver) . '_TESTDB_HOST'),
            'port' => self::getEnvironmentVariable(strtoupper($driver) . '_TESTDB_PORT'),
            'username' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB_USER'),
            'password' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB_PASSWORD'),
            'dbname' => self::getEnvironmentVariable(strtoupper($driver) . '_ICINGAWEBDB')
        ]);
    }

    /**
     * Fork the current process and return true in the child process and false in the parent process
     *
     * @return bool
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function fork(): bool
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            echo "Could not fork\n";
            exit(2);
        } elseif ($pid) {
            register_shutdown_function(function () use ($pid) {
                posix_kill($pid, SIGTERM);
            });

            return false;
        }

        return true;
    }
}
