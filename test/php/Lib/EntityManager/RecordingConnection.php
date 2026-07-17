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
        $this->calls[] = [
            'method' => 'insert',
            'table'  => $this->unquote($table),
            'data'   => $this->unquoteKeys($data),
        ];

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
            'table'     => is_string($table) ? $this->unquote($table) : $table,
            'data'      => $this->unquoteKeys($data),
            'condition' => is_array($condition) ? $this->unquoteKeys($condition) : $condition,
        ];

        return parent::update($table, $data, $condition, $operator);
    }

    public function delete(
        string|array $table,
        string|array|null $condition = null,
        string $operator = Sql::ALL
    ): PDOStatement {
        $this->calls[] = [
            'method'    => 'delete',
            'table'     => is_string($table) ? $this->unquote($table) : $table,
            'condition' => is_array($condition) ? $this->unquoteKeys($condition) : $condition,
        ];

        return parent::delete($table, $condition, $operator);
    }

    /**
     * Strip the adapter's identifier quoting from a recorded string
     *
     * The EntityManager quotes every identifier it emits ({@see Connection::quoteIdentifier()}). The recorded
     * calls are normalized back to their logical names so assertions can express the expected write set in plain
     * table/column names, independent of the adapter's quote character. Only the real SQL forwarded to the parent
     * keeps the quoting.
     *
     * @param string $identifier
     *
     * @return string
     */
    private function unquote(string $identifier): string
    {
        $quoted = $this->quoteIdentifier('x');

        return str_replace([$quoted[0], $quoted[strlen($quoted) - 1]], '', $identifier);
    }

    /**
     * Copy a map with its keys (quoted identifiers or `column = ?` expressions) unquoted via {@see self::unquote()}
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function unquoteKeys(array $data): array
    {
        $unquoted = [];
        foreach ($data as $key => $value) {
            $unquoted[$this->unquote((string) $key)] = $value;
        }

        return $unquoted;
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
