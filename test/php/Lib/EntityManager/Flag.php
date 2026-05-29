<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behaviors;

class Flag extends Model
{
    public function getTableName()
    {
        return 'flag';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['label', 'enabled'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new BoolCast(['enabled']));
    }
}
