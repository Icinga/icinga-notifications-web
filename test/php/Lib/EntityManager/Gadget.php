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

        $relations->belongsToMany('sticker', Sticker::class)
            ->through('gadget_sticker');

        // Declared through a junction model that carries a `deleted` column, so the EntityManager
        // syncs these links with soft-deletes and revives instead of hard deletes.
        $relations->belongsToMany('tag', Tag::class)
            ->through(GadgetTag::class);

        // Like `tag`, but its junction model is keyed by a surrogate `id` rather than the natural
        // gadget_id/badge_id pair, mirroring the real rule_escalation_recipient table.
        $relations->belongsToMany('badge', Badge::class)
            ->through(GadgetBadge::class);
    }
}
