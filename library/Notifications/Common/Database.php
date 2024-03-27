<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Icinga\Application\Config as AppConfig;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use ipl\Orm\Query;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Config as SqlConfig;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\QueryBuilder;
use ipl\Sql\Select;
use PDO;

final class Database
{
    /** @var Connection Database connection */
    private static $instance;

    /** Singleton class */
    private function __construct()
    {
    }

    /**
     * Get the database connection
     *
     * @return Connection
     */
    public static function get(): Connection
    {
        if (self::$instance === null) {
            self::$instance = self::getConnection();
        }

        return self::$instance;
    }

    /**
     * Get the database connection
     *
     * @throws ConfigurationError If the related resource configuration does not exist
     */
    private static function getConnection(): Connection
    {
        $config = new SqlConfig(ResourceFactory::getResourceConfig(
            AppConfig::module('notifications')->get('database', 'resource', 'notifications')
        ));

        $config->options = [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ];
        if ($config->db === 'mysql') {
            $config->options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION SQL_MODE='STRICT_TRANS_TABLES"
                . ",NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";
        }

        $db = new Connection($config);

        $adapter = $db->getAdapter();
        if ($adapter instanceof Pgsql) {
            $db->getQueryBuilder()
                ->on(QueryBuilder::ON_ASSEMBLE_SELECT, function (Select $select) {
                    // For SELECT DISTINCT, all ORDER BY columns must appear in SELECT list.
                    if (! $select->getDistinct() || ! $select->hasOrderBy()) {
                        return;
                    }

                    $candidates = [];
                    foreach ($select->getOrderBy() as list($columnOrAlias, $_)) {
                        if ($columnOrAlias instanceof Expression) {
                            // Expressions can be and include anything,
                            // also columns that aren't already part of the SELECT list,
                            // so we're not trying to guess anything here.
                            // Such expressions must be in the SELECT list if necessary and
                            // referenced manually with an alias in ORDER BY.
                            continue;
                        }

                        $candidates[$columnOrAlias] = true;
                    }

                    foreach ($select->getColumns() as $alias => $column) {
                        if (is_int($alias)) {
                            if ($column instanceof Expression) {
                                // This is the complement to the above consideration.
                                // If it is an unaliased expression, ignore it.
                                continue;
                            }
                        } else {
                            unset($candidates[$alias]);
                        }

                        if (! $column instanceof Expression) {
                            unset($candidates[$column]);
                        }
                    }

                    if (! empty($candidates)) {
                        $select->columns(array_keys($candidates));
                    }
                });
        }

        return $db;
    }

    /**
     * Generate a group by expression and register it on the given select
     *
     * @param Query $query
     * @param Select $select
     *
     * @return void
     */
    public static function registerGroupBy(Query $query, Select $select): void
    {
        $resolver = $query->getResolver();

        $groupBy = [];
        foreach ((array) $query->getModel()->getKeyName() as $key) {
            $groupBy[] = $resolver->qualifyColumn($key, $resolver->getAlias($query->getModel()));
        }

        foreach ($query->getWith() as $relation) {
            foreach ((array) $relation->getTarget()->getKeyName() as $key) {
                $groupBy[] = $resolver->qualifyColumn($key, $resolver->getAlias($relation->getTarget()));
            }
        }

        // For PostgreSQL, ALL non-aggregate SELECT columns must appear in the GROUP BY clause:
        if ($query->getDb()->getAdapter() instanceof Pgsql) {
            /**
             * Ignore Expressions, i.e. aggregate functions {@see getColumns()},
             * which do not need to be added to the GROUP BY.
             */
            $candidates = array_filter($select->getColumns(), 'is_string');
            // Remove already considered columns for the GROUP BY
            $candidates = array_diff($candidates, $groupBy);
            $groupBy = array_merge($groupBy, $candidates);
        }

        $select->groupBy($groupBy);
    }
}
