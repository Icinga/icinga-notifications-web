<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class Schedule
{
    /**
     * @param ?int $id The primary database key value, NULL for new schedules
     * @param string $name The name of the schedule
     * @param ?string $timezone The timezone of the schedule, NULL in case no change is necessary
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $timezone
    ) {
    }
}
