<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use ipl\Sql\Connection;
use ipl\Sql\Sql;
use PDOStatement;

/**
 * Test double for {@see Connection} that records every write call and forwards to the real parent.
 *
 * Lets tests assert that the EntityManager issues the *exact* set of writes expected — and skips
 * the ones it shouldn't — without giving up the real sqlite-backed end-to-end execution.
 */
class RecordingConnection extends Connection
{
    /**
     * Each entry is one recorded write keyed by `method` ('insert'|'update'|'delete'), plus `table`,
     * `data` (for insert/update), and `condition` (for update/delete).
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    public function insert(string $table, iterable $data): PDOStatement
    {
        $data = is_array($data) ? $data : iterator_to_array($data);
        $this->calls[] = ['method' => 'insert', 'table' => $table, 'data' => $data];

        return parent::insert($table, $data);
    }

    public function update(
        string|array $table,
        iterable $data,
        string|array|null $condition = null,
        string $operator = Sql::ALL
    ): PDOStatement {
        $data = is_array($data) ? $data : iterator_to_array($data);
        $this->calls[] = [
            'method'    => 'update',
            'table'     => $table,
            'data'      => $data,
            'condition' => $condition,
        ];

        return parent::update($table, $data, $condition, $operator);
    }

    public function delete(
        string|array $table,
        string|array|null $condition = null,
        string $operator = Sql::ALL
    ): PDOStatement {
        $this->calls[] = ['method' => 'delete', 'table' => $table, 'condition' => $condition];

        return parent::delete($table, $condition, $operator);
    }

    /**
     * Drop the recorded calls so subsequent assertions only see writes from the next action
     *
     * @return void
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }
}
