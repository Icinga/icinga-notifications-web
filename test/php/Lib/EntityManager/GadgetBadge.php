<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;

/**
 * Soft-delete junction model linking {@see Gadget} and {@see Badge} through a surrogate primary key.
 *
 * Unlike {@see GadgetTag} (whose primary key is the natural pair of foreign keys, mirroring
 * contactgroup_member), this junction is keyed by a standalone `id` and carries a `deleted` column,
 * mirroring the real rule_escalation_recipient table. The surrogate key is not part of the source or
 * target columns, so reviving or soft-deleting a link must load and carry it to scope the UPDATE.
 */
class GadgetBadge extends Model
{
    public function getTableName(): string
    {
        return 'gadget_badge';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return ['gadget_id', 'badge_id', 'changed_at', 'deleted'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function isSoftDeletable(): bool
    {
        return true;
    }
}
