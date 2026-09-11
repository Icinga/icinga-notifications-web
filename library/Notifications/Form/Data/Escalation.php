<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class Escalation
{
    /**
     * @param ?int $id The primary database key value, NULL for new escalations
     * @param int $position The position of the escalation in the rule
     * @param ?string $condition The conditions of the escalation
     * @param EscalationRecipient[] $recipients Escalation recipients
     * @param int $ruleId The ID of the rule the escalation belongs to
     */
    public function __construct(
        public ?int $id,
        public int $position,
        public ?string $condition,
        public array $recipients,
        public int $ruleId
    ) {
    }
}
