<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class StampedNote extends Model
{
    public function getTableName(): string
    {
        return 'stamped_note';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return ['stamped_id', 'text'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('stamped', Stamped::class);
    }
}
