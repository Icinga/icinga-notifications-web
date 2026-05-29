<?php

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use DateTime;
use Icinga\Module\Notifications\Common\EntityManager;

/**
 * Test double for {@see EntityManager} that returns deterministic, monotonically increasing
 * timestamps from {@see now()}, so `changed_at`-stamping assertions can use exact values.
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