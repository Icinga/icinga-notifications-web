<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib;

use ipl\Orm\Model;
use ipl\Sql\Connection;
use ipl\Sql\Select;

trait DatabaseUtils
{
    /**
     * Load a database row directly, bypassing the model's `deleted` filter
     *
     * @template TModel of Model
     *
     * @param Connection $db
     * @param int|int[] $id
     * @param class-string<TModel> $class
     *
     * @return ?TModel
     */
    private function loadRawEntity(Connection $db, int|array $id, string $class): ?Model
    {
        $entity = new $class();
        $where = [];
        if (is_array($id)) {
            foreach ($id as $k => $v) {
                $where["$k = ?"] = $v;
            }
        } else {
            $k = ((array) $entity->getKeyName())[0];
            $where["$k = ?"] = $id;
        }

        $result = $db->select(
            (new Select())
                ->from($entity->getTableName())
                ->columns(array_map($db->quoteIdentifier(...), array_merge(
                    (array) $entity->getKeyName(),
                    $entity->getColumns()
                )))
                ->where($where)
        )->fetch(\PDO::FETCH_ASSOC);
        if ($result === false) {
            return null;
        }

        $entity->setProperties($result);

        return $entity;
    }
}
