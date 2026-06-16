<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use DateTime;
use Icinga\Module\Notifications\Common\EntityManager;

/**
 * Test double for {@see EntityManager} with two knobs:
 *
 *  - {@see now()} returns deterministic, monotonically increasing timestamps, so `changed_at`-stamping
 *    assertions can use exact values.
 *  - {@see softDeleteTables()} reports the junction tables to treat as soft-delete. It is empty by
 *    default (so every junction is hard-deleted unless its model declares a `deleted` column), and a
 *    test sets {@see $softDeleteTableNames} to exercise the table-name soft-delete path that the real
 *    contactgroup_member / rule_escalation_recipient junctions take.
 */
class TickingEntityManager extends EntityManager
{
    /** @var int Seconds since the epoch returned by the next call to {@see now()}. Reset per test. */
    public static int $tick = 0;

    /** @var list<string> Junction table names this manager treats as soft-delete (empty by default) */
    public array $softDeleteTableNames = [];

    protected function now(): DateTime
    {
        return new DateTime('@' . ++self::$tick);
    }

    protected function softDeleteTables(): array
    {
        return $this->softDeleteTableNames;
    }
}
