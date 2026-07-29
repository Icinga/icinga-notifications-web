<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class Channel
{
    /**
     * @param ?int $id The primary database key value, NULL for new channels
     * @param string $name The channel name
     * @param string $type The channel type
     * @param array<string, mixed> $config The type specific configuration of the channel
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $type,
        public array $config
    ) {
    }
}
