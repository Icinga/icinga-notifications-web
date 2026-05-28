<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model\Behavior;

use DateTime;
use ipl\Orm\Contract\PersistBehavior;
use ipl\Orm\Model;

/**
 * Sets a timestamp column to the current time on every save
 *
 * Pair with {@see \ipl\Orm\Behavior\MillisecondTimestamp} on the same column so the {@see DateTime}
 * value this behavior writes is converted to the column's storage format on the way to the database.
 */
class ChangedAt implements PersistBehavior
{
    public function __construct(private string $column = 'changed_at')
    {
    }

    public function persist(Model $model): void
    {
        $model->{$this->column} = $this->now();
    }

    protected function now(): DateTime
    {
        return new DateTime();
    }
}
