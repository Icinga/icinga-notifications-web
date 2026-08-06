<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

readonly class ContactGroup
{
    /**
     * @param ?int $id The primary database key value, NULL for new contact groups
     * @param string $name The name of the contact group
     * @param int[] $members The group's members, a list of contact IDs
     * @param ?string $externalUuid How external systems reference the group, NULL to generate one.
     *                              Only honored when the group is created
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public array $members = [],
        public ?string $externalUuid = null
    ) {
    }
}
