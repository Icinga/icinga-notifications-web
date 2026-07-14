<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\EntityManager;

/**
 * Test double for {@see EntityManager} whose {@see now()} returns a deterministic, monotonically
 * increasing tick (1000, 2000, 3000, … ms) instead of the wall clock, so `changed_at`-stamping
 * assertions can use exact values.
 */
class TickingEntityManager extends EntityManager
{
    /** @var int Ticks elapsed; the next {@see now()} returns (this + 1) * 1000. Reset per test. */
    public static int $tick = 0;

    protected function now(): int
    {
        return ++static::$tick * 1000;
    }
}
