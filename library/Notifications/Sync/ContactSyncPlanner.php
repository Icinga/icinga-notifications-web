<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Sync;

use Ramsey\Uuid\Uuid;

/**
 * Determines how to bring contacts and contact groups in line with rows from an external source (e.g. LDAP)
 *
 * Contacts are matched by username. Contacts and groups created by a sync get a name based UUID (v5) derived
 * from the sync's name, which is how a later sync recognizes them as its own:
 *
 * - Only contacts owned by the sync are purged once they vanish from the source. Contacts which existed
 *   before and were matched by username are updated, but never deleted.
 * - Group memberships are only managed for groups owned by the sync or referenced by the source. Memberships
 *   in other groups, which were assigned manually, are preserved.
 * - Only the address types mapped by the sync are managed, other addresses of a contact are preserved.
 * - The default channel is only set when a contact is created, so users may choose a different one.
 */
class ContactSyncPlanner
{
    /** @var string Namespace of the UUIDs that identify a sync */
    public const NAMESPACE = 'c2a1f0e4-5b7d-4f3e-9a61-0d7e3b9f4a12';

    /** @var int Maximum length of a contact's username */
    public const MAX_USERNAME_LENGTH = 254;

    /** @var string Channel type which does not require an address */
    private const ADDRESSLESS_CHANNEL_TYPE = 'webhook';

    /** @var string Namespace of the UUIDs of the contacts and groups owned by this sync */
    private string $namespace;

    /**
     * Create a new ContactSyncPlanner
     *
     * @param string $syncName Identifies the sync, contacts and groups are owned by the sync with the same name
     * @param int $defaultChannelId The channel assigned to new contacts
     * @param array<int, string> $channelTypes Channel types indexed by channel ID
     * @param bool $purge Whether to delete owned contacts which are no longer part of the source
     */
    public function __construct(
        string $syncName,
        private int $defaultChannelId,
        private array $channelTypes,
        private bool $purge = false
    ) {
        $this->namespace = Uuid::uuid5(self::NAMESPACE, $syncName)->toString();
    }

    /**
     * Get the UUID of the contact with the given username if it is owned by this sync
     *
     * @param string $username
     *
     * @return string
     */
    public function contactUuid(string $username): string
    {
        return Uuid::uuid5($this->namespace, 'contact:' . mb_strtolower($username))->toString();
    }

    /**
     * Get the UUID of the group with the given name if it is owned by this sync
     *
     * @param string $name
     *
     * @return string
     */
    public function groupUuid(string $name): string
    {
        return Uuid::uuid5($this->namespace, 'group:' . mb_strtolower($name))->toString();
    }

    /**
     * Extract a source row from the given imported row
     *
     * Multi-valued properties (e.g. LDAP attributes) are reduced to their first value, except for groups.
     *
     * @param object $row
     * @param string $usernameColumn
     * @param ?string $fullNameColumn
     * @param array<string, string> $addressColumns Columns indexed by address type
     * @param ?string $groupsColumn
     *
     * @return array{username: ?string, fullName: ?string, addresses: array<string, ?string>, groups: string[]}
     */
    public static function extractRow(
        object $row,
        string $usernameColumn,
        ?string $fullNameColumn = null,
        array $addressColumns = [],
        ?string $groupsColumn = null
    ): array {
        $addresses = [];
        foreach ($addressColumns as $type => $column) {
            $addresses[$type] = self::scalarProperty($row, $column);
        }

        $groups = [];
        if ($groupsColumn !== null) {
            $value = $row->$groupsColumn ?? null;
            foreach (is_array($value) ? $value : [$value] as $group) {
                if (is_scalar($group) && ($group = trim((string) $group)) !== '') {
                    $groups[] = $group;
                }
            }
        }

        return [
            'username'  => self::scalarProperty($row, $usernameColumn),
            'fullName'  => $fullNameColumn === null ? null : self::scalarProperty($row, $fullNameColumn),
            'addresses' => $addresses,
            'groups'    => $groups
        ];
    }

    /**
     * Plan the changes required to bring the given contacts and groups in line with the given source rows
     *
     * @param iterable<array{
     *     username: ?string,
     *     fullName: ?string,
     *     addresses: array<string, ?string>,
     *     groups: string[]
     * }> $rows Source rows as returned by {@see self::extractRow()}
     * @param iterable<array{
     *     id: int,
     *     username: ?string,
     *     fullName: string,
     *     channelId: int,
     *     uuid: ?string,
     *     addresses: array<string, string>,
     *     groups: int[]
     * }> $contacts All existing contacts
     * @param iterable<array{id: int, name: string, uuid: ?string}> $groups All existing contact groups
     *
     * @return ContactSyncPlan
     */
    public function plan(iterable $rows, iterable $contacts, iterable $groups): ContactSyncPlan
    {
        $plan = new ContactSyncPlan();

        $contactsByUsername = [];
        foreach ($contacts as $contact) {
            if ($contact['username'] !== null) {
                $contactsByUsername[mb_strtolower($contact['username'])] = $contact;
            }
        }

        $groupsByName = [];
        $managedGroupIds = [];
        foreach ($groups as $group) {
            $name = mb_strtolower($group['name']);
            $isOwned = $this->isUuid($group['uuid'], $this->groupUuid($group['name']));
            if ($isOwned) {
                $managedGroupIds[$group['id']] = true;
            }

            // Names are not unique, prefer the group owned by this sync and the oldest one otherwise
            if (! isset($groupsByName[$name]) || $isOwned) {
                $groupsByName[$name] = $group['id'];
            }
        }

        $validRows = [];
        foreach ($rows as $row) {
            $username = trim((string) $row['username']);
            if ($username === '') {
                $plan->warnings[] = 'Skipping row without username';
                continue;
            }

            if (mb_strlen($username) > self::MAX_USERNAME_LENGTH) {
                $plan->warnings[] = sprintf(
                    'Skipping %s, the username exceeds %d characters',
                    $username,
                    self::MAX_USERNAME_LENGTH
                );
                continue;
            }

            $key = mb_strtolower($username);
            if (isset($validRows[$key])) {
                $plan->warnings[] = sprintf('Skipping duplicate username %s', $username);
                continue;
            }

            $validRows[$key] = ['username' => $username] + $row;

            foreach ($row['groups'] as $groupName) {
                $groupKey = mb_strtolower($groupName);
                if (isset($groupsByName[$groupKey])) {
                    $managedGroupIds[$groupsByName[$groupKey]] = true;
                } elseif (! isset($plan->createGroups[$groupKey])) {
                    $plan->createGroups[$groupKey] = ['name' => $groupName, 'uuid' => $this->groupUuid($groupName)];
                }
            }
        }

        foreach ($validRows as $key => $row) {
            $existing = $contactsByUsername[$key] ?? null;

            $addresses = $existing['addresses'] ?? [];
            foreach ($row['addresses'] as $type => $address) {
                $address = $address === null ? '' : trim($address);
                if ($address === '') {
                    unset($addresses[$type]);
                } else {
                    $addresses[$type] = $address;
                }
            }

            $channelId = $existing['channelId'] ?? $this->defaultChannelId;
            $channelType = $this->channelTypes[$channelId] ?? null;
            if (
                $channelType !== null
                && $channelType !== self::ADDRESSLESS_CHANNEL_TYPE
                && empty($addresses[$channelType])
            ) {
                $plan->warnings[] = sprintf(
                    'Skipping %s, an address of type %s is required by its default channel',
                    $row['username'],
                    $channelType
                );
                continue;
            }

            $groupRefs = [];
            foreach ($existing['groups'] ?? [] as $groupId) {
                if (! isset($managedGroupIds[$groupId])) {
                    $groupRefs[] = $groupId;
                }
            }

            foreach ($row['groups'] as $groupName) {
                $groupKey = mb_strtolower($groupName);
                $groupRefs[] = $groupsByName[$groupKey] ?? $groupKey;
            }

            $groupRefs = array_values(array_unique($groupRefs, SORT_REGULAR));
            $fullName = trim((string) $row['fullName']);
            if ($fullName === '') {
                $fullName = $row['username'];
            }

            if ($existing === null) {
                $plan->createContacts[] = [
                    'username'  => $row['username'],
                    'fullName'  => $fullName,
                    'channelId' => $channelId,
                    'addresses' => $addresses,
                    'groups'    => $groupRefs,
                    'uuid'      => $this->contactUuid($row['username'])
                ];
            } elseif (
                $existing['fullName'] !== $fullName
                || ! self::equalsUnordered($existing['addresses'], $addresses)
                || ! self::equalsUnordered($existing['groups'], $groupRefs, false)
            ) {
                $plan->updateContacts[] = [
                    'id'        => $existing['id'],
                    'username'  => $existing['username'],
                    'fullName'  => $fullName,
                    'channelId' => $channelId,
                    'addresses' => $addresses,
                    'groups'    => $groupRefs
                ];
            }
        }

        if ($this->purge) {
            foreach ($contactsByUsername as $key => $contact) {
                if (
                    ! isset($validRows[$key])
                    && $this->isUuid($contact['uuid'], $this->contactUuid($contact['username']))
                ) {
                    $plan->deleteContacts[$contact['id']] = $contact['username'];
                }
            }
        }

        return $plan;
    }

    /**
     * Get the first scalar value of the given property, NULL if it is not set or empty
     *
     * @param object $row
     * @param string $property
     *
     * @return ?string
     */
    private static function scalarProperty(object $row, string $property): ?string
    {
        $value = $row->$property ?? null;
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Get whether the given UUIDs are equal
     *
     * @param ?string $uuid
     * @param string $expected
     *
     * @return bool
     */
    private function isUuid(?string $uuid, string $expected): bool
    {
        return $uuid !== null && strtolower($uuid) === $expected;
    }

    /**
     * Get whether the given arrays have the same elements, regardless of their order
     *
     * @param array<int|string, int|string> $a
     * @param array<int|string, int|string> $b
     * @param bool $withKeys Whether keys are significant
     *
     * @return bool
     */
    private static function equalsUnordered(array $a, array $b, bool $withKeys = true): bool
    {
        if ($withKeys) {
            ksort($a);
            ksort($b);
        } else {
            sort($a);
            sort($b);
        }

        return $a === $b;
    }
}
