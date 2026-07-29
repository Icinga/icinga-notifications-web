<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class EscalationRule
{
    /**
     * @param ?int $id The primary database key value, NULL for new rules
     * @param string $name The name of the rule
     * @param int $sourceId The source's ID the rule belongs to
     * @param ?string $objectFilter The object filter of the rule
     * @param Escalation[] $escalations
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public int $sourceId,
        public ?string $objectFilter,
        public array $escalations
    ) {
    }
}
