<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Sync;

/**
 * The changes {@see ContactSyncPlanner} determined to bring the contacts in line with the source rows
 *
 * Group references in `groups` are either the ID of an existing contact group (int) or the lower-cased name
 * of a group listed in {@see self::$createGroups} (string), which has no ID until it is created.
 */
class ContactSyncPlan
{
    /** @var array<string, array{name: string, uuid: string}> Groups to create, indexed by lower-cased name */
    public array $createGroups = [];

    /**
     * @var list<array{
     *     username: string,
     *     fullName: string,
     *     channelId: int,
     *     addresses: array<string, string>,
     *     groups: list<int|string>,
     *     uuid: string
     * }>
     */
    public array $createContacts = [];

    /**
     * @var list<array{
     *     id: int,
     *     username: string,
     *     fullName: string,
     *     channelId: int,
     *     addresses: array<string, string>,
     *     groups: list<int|string>
     * }>
     */
    public array $updateContacts = [];

    /** @var array<int, string> Contacts to delete, username indexed by ID */
    public array $deleteContacts = [];

    /** @var list<string> Rows that were skipped and why */
    public array $warnings = [];

    /**
     * Get whether this plan changes anything
     *
     * @return bool
     */
    public function hasChanges(): bool
    {
        return ! empty($this->createGroups)
            || ! empty($this->createContacts)
            || ! empty($this->updateContacts)
            || ! empty($this->deleteContacts);
    }
}
