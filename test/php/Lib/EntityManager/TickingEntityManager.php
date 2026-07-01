<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use DateTime;
use Icinga\Module\Notifications\Common\EntityManager;

/**
 * Test double for {@see EntityManager} whose {@see now()} returns deterministic, monotonically
 * increasing timestamps, so `changed_at`-stamping assertions can use exact values.
 */
class TickingEntityManager extends EntityManager
{
    /** @var int Seconds since the epoch returned by the next call to {@see now()}. Reset per test. */
    public static int $tick = 0;

    protected function now(): DateTime
    {
        return new DateTime('@' . ++self::$tick);
    }
}
