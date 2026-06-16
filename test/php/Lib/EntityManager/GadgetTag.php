<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;

/**
 * Soft-delete junction model linking {@see Gadget} and {@see Tag}.
 *
 * Carries a `deleted` column (and `changed_at`), so the EntityManager reconciles the link table with
 * soft-deletes and revives rather than hard deletes — mirroring the real contactgroup_member and
 * rule_escalation_recipient junctions.
 */
class GadgetTag extends Model
{
    public function getTableName(): string
    {
        return 'gadget_tag';
    }

    public function getKeyName(): array
    {
        return ['gadget_id', 'tag_id'];
    }

    public function getColumns(): array
    {
        return ['gadget_id', 'tag_id', 'changed_at', 'deleted'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }
}
