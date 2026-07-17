<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;

/**
 * HasMany child of {@see Stamped} that carries a `deleted` column, so the EntityManager soft-deletes
 * an orphaned note (via sync()) or a detached note rather than removing the row. It also carries a
 * `changed_at` column so the baseline (optimistic locking) check has a modification time to compare.
 */
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
        return ['stamped_id', 'text', 'changed_at', 'deleted'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('stamped', Stamped::class);
    }
}
