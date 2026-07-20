<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Test;

use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases;
use RuntimeException;

/**
 * Data provider for database tests
 *
 * To use it, implement {@see DbTestBackends::initializeNotificationsDb()}.
 *
 * @internal This trait is only intended for use by the Icinga\Module\Notifications test suite.
 */
trait DbTestBackends
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
     * Initialize the configuration for the database tests
     *
     * @param Connection $db
     *
     * @return void
     */
    abstract protected static function initializeNotificationsDb(Connection $db): void;

    public static function setUpSchema(Connection $db, string $driver): void
    {
        $notificationSchemaPath = getenv('ICINGA_NOTIFICATIONS_SCHEMA');
        if (! $notificationSchemaPath) {
            throw new RuntimeException('Environment variable ICINGA_NOTIFICATIONS_SCHEMA is not set');
        }

        $notificationSchema = $notificationSchemaPath . "/$driver/schema.sql";
        if (! file_exists($notificationSchema)) {
            throw new RuntimeException("Schema file $notificationSchema does not exist");
        }

        $statements = file_get_contents($notificationSchema);

        if (preg_match('/\s*delimiter\s*(\S+)\s*$/im', $statements, $matches)) {
            $statements = preg_replace('/\s*delimiter\s*(\S+)\s*$/im', '', $statements);
            $statements = preg_replace('/' . preg_quote($matches[1], '/') . '$/m', ';', $statements);
        }

        $db->exec($statements);

        static::initializeNotificationsDb($db);
    }

    public static function tearDownSchema(Connection $db, string $driver): void
    {
        if ($driver === 'mysql') {
            $db->exec(self::MYSQL_DROP_PROCEDURE);

            $db->exec(self::MYSQL_PROCEDURE_CALL);
        } elseif ($driver === 'pgsql') {
            $db->exec(self::PGSQL_DROP_PROCEDURE);
        }
    }
}
