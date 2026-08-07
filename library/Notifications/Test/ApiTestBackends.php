<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Test;

use Icinga\Application\Config;
use Icinga\Web\Request;
use Icinga\Web\Url;
use ipl\Sql\Connection;
use RuntimeException;

/**
 * Data provider for API tests
 *
 * To use it, implement {@see DbTestBackends::initializeNotificationsDb()}. The environment also needs to provide
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
    use DbTestBackends {
        setUpSchema as protected baseSetUpSchema;
        tearDownSchema as protected baseTearDownSchema;
    }

    /**
     * All backend endpoints
     *
     * @internal Only the trait itself should access this property
     *
     * @var array<string, array{0: Connection, 1: Url}>
     */
    private static array $backends = [];

    /**
     * Provide the endpoints for the API tests plus their accompanying database connections
     *
     * @return array<string, array{0: Connection, 1: Url}>
     */
    final public static function apiTestBackends(): array
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

            self::initializeIcingaWeb($name, $configDir, $connection[0]->getConfig());

            if (self::fork()) {
                $env = ['ICINGAWEB_CONFIGDIR' => $configDir];

                $libDir = getenv('ICINGAWEB_LIBDIR');
                if ($libDir !== false) {
                    $env['ICINGAWEB_LIBDIR'] = $libDir;
                }

                if (ini_get('xdebug.mode') === 'debug') {
                    if (($ideConfig = getenv('PHP_IDE_CONFIG'))) {
                        $env['PHP_IDE_CONFIG'] = $ideConfig;
                    }

                    $env['XDEBUG_MODE'] = 'debug';
                    $env['XDEBUG_CONFIG'] = sprintf(
                        '%s client_host=%s client_port=%s',
                        getenv('XDEBUG_CONFIG') ?: '',
                        ini_get('xdebug.client_host'),
                        ini_get('xdebug.client_port')
                    );
                }

                pcntl_exec(
                    readlink('/proc/self/exe'),
                    ['-q', '-S', $socket, '-t', "$webPath/public", "$webPath/public/index.php"],
                    $env
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
     * @param \ipl\Sql\Config $connectionConfig
     *
     * @return void
     *
     * @internal Only the trait itself should access this method
     */
    final protected static function initializeIcingaWeb(
        string $driver,
        string $configDir,
        \ipl\Sql\Config $connectionConfig
    ): void {
        $oldConfigDir = Config::$configDir;
        Config::$configDir = $configDir;

        Config::app(fromDisk: true)
            ->setSection('global', [
                'config_resource' => 'web_db'
            ])->setSection('logging', [
                'log' => 'file',
                'file' => $configDir . '/icingaweb.log',
                'level' => 'debug'
            ])->saveIni();
        Config::app('resources', true)
            ->setSection('web_db', [
                'type' => 'db',
                'db' => $connectionConfig->db,
                'host' => $connectionConfig->host,
                'port' => $connectionConfig->port,
                'dbname' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB'),
                'username' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB_USER'),
                'password' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB_PASSWORD')
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
        self::baseSetUpSchema($db, $driver);

        $webSchema = self::getIcingaWebPath() . "/schema/$driver.schema.sql";
        $webDb = self::connectToIcingaWebDb($driver);
        $webDb->exec(file_get_contents($webSchema));
        self::initializeIcingaWebDb($webDb, $driver);
    }

    final protected static function tearDownSchema(Connection $db, string $driver): void
    {
        self::baseTearDownSchema($db, $driver);

        $webDb = self::connectToIcingaWebDb($driver);
        if ($driver === 'mysql') {
            $webDb->exec(self::MYSQL_DROP_PROCEDURE);
            $webDb->exec(self::MYSQL_PROCEDURE_CALL);
        } elseif ($driver === 'pgsql') {
            $webDb->exec(self::PGSQL_DROP_PROCEDURE);
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
            'host' => self::getEnvVariable(strtoupper($driver) . '_TESTDB_HOST'),
            'port' => self::getEnvVariable(strtoupper($driver) . '_TESTDB_PORT'),
            'username' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB_USER'),
            'password' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB_PASSWORD'),
            'dbname' => self::getEnvVariable(strtoupper($driver) . '_ICINGAWEBDB')
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

    /**
     * Get the value of an environment variable
     *
     * @param string $name
     *
     * @return string
     *
     * @throws RuntimeException if the environment variable is not set
     *
     * @internal Only the trait itself should access this method
     */
    private static function getEnvVariable(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            throw new RuntimeException("Environment variable $name is not set");
        }

        return $value;
    }
}
