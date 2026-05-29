<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Gadget extends Model
{
    public function getTableName()
    {
        return 'gadget';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['workshop_id', 'name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('workshop', Workshop::class);

        $relations->belongsToMany('stickers', Sticker::class)
            ->through('gadget_sticker');
    }
}
