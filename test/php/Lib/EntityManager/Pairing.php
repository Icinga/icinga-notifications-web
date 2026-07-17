<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;

/**
 * Compound-key fixture whose table and columns are deliberately SQL reserved keywords.
 *
 * `values` (table), `order`/`group` (the compound primary key) and `insert` (a plain column) are reserved in
 * both sqlite and MySQL, so every identifier the {@see EntityManager} emits for this model must be quoted or the
 * statement is a syntax error. This exercises the quoting of table names, INSERT/UPDATE data columns and the
 * compound primary key WHERE across the insert/update/delete lifecycle.
 */
class Pairing extends Model
{
    public function getTableName(): string
    {
        return 'values';
    }

    public function getKeyName(): array
    {
        return ['order', 'group'];
    }

    public function getColumns(): array
    {
        return ['order', 'group', 'insert'];
    }
}
