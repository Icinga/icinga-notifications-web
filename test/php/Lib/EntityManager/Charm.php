<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;

class Charm extends Model
{
    public function getTableName()
    {
        return 'charm';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['trinket_id', 'label'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['trinket_id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('trinket', Trinket::class);
    }
}
