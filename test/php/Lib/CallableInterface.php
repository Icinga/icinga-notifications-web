<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Lib;

/**
 * A callable interface to be used in tests for mocking closures.
 * Accepts any arguments for general purpose.
 */
interface CallableInterface
{
    public function __invoke(...$args);
}
