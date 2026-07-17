<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Badge extends Model
{
    public function getTableName(): string
    {
        return 'badge';
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
        // Reciprocal of Gadget->badge, named after Gadget's table (see the note on Tag->gadget)
        $relations->belongsToMany('gadget', Gadget::class)
            ->through(GadgetBadge::class);
    }
}
