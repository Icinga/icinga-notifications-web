<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;

class Stamped extends Model
{
    public function getTableName()
    {
        return 'stamped';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name', 'changed_at'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('notes', StampedNote::class);
    }
}
