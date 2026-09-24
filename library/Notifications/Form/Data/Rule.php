<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class Rule
{
    /**
     * @param ?int $id The primary database key value, NULL for new rules
     * @param string $name The name of the rule
     * @param string $sourceType The source type the rule belongs to
     * @param ?string $objectFilter The object filter of the rule, NULL for no change
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $sourceType,
        public ?string $objectFilter
    ) {
    }
}
