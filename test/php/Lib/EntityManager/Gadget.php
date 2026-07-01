<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Gadget extends Model
{
    public function getTableName(): string
    {
        return 'gadget';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return ['workshop_id', 'name'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('workshop', Workshop::class);

        $relations->belongsToMany('stickers', Sticker::class)
            ->through('gadget_sticker');

        // Declared through a junction model that carries a `deleted` column, so the EntityManager
        // syncs these links with soft-deletes and revives instead of hard deletes.
        $relations->belongsToMany('tags', Tag::class)
            ->through(GadgetTag::class);
    }
}
