<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Tag extends Model
{
    public function getTableName(): string
    {
        return 'tag';
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
        // Reciprocal of Gadget->tag, named after Gadget's table so the many-to-many relation is lazily
        // readable from a loaded Tag: Query::derive() joins the target back through a relation named
        // after the source's table.
        $relations->belongsToMany('gadget', Gadget::class)
            ->through(GadgetTag::class);
    }
}
