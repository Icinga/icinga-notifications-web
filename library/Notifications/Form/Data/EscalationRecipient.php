<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class EscalationRecipient
{
    /**
     * @param ?int $id The primary database key value, NULL for new recipients
     * @param 'contact'|'contact_group'|'schedule' $type The type of recipient
     * @param int $recipientId The recipient ID
     * @param ?int $channelId The channel ID, NULL for the default channel
     */
    public function __construct(
        public ?int $id,
        public string $type,
        public int $recipientId,
        public ?int $channelId
    ) {
    }
}
