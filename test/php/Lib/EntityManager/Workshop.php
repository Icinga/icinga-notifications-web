<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Workshop extends Model
{
    public function getTableName(): string
    {
        return 'workshop';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return ['name'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('gadgets', Gadget::class);
    }
}
