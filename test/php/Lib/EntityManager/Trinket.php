<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;

class Trinket extends Model
{
    public function getTableName()
    {
        return 'trinket';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('charms', Charm::class);
    }
}
