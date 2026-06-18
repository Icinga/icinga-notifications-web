<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class Contact
{
    /**
     * @param ?int $id The primary database key value, NULL for new contacts
     * @param string $fullName How to address the contact in notifications
     * @param ?string $username The Icinga Web user associated with the contact
     * @param int $channelId Id of the default channel for the contact
     * @param array<string, string> $addresses Type identifiers as keys and addresses as values
     */
    public function __construct(
        public ?int $id,
        public string $fullName,
        public ?string $username,
        public int $channelId,
        public array $addresses
    ) {
    }
}
