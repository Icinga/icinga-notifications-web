<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;

class Pairing extends Model
{
    public function getTableName(): string
    {
        return 'pairing';
    }

    public function getKeyName(): array
    {
        return ['left_id', 'right_id'];
    }

    public function getColumns(): array
    {
        return ['left_id', 'right_id', 'label'];
    }
}
