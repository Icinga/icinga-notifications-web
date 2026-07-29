<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form;

use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;

interface ConfigProviderInterface
{
    /**
     * Get a list of all available contacts
     *
     * @return iterable<Contact>
     */
    public function fetchContacts(): iterable;

    /**
     * Get a list of all available contact groups
     *
     * @return iterable<Contactgroup>
     */
    public function fetchContactGroups(): iterable;

    /**
     * Get a list of all available schedules
     *
     * @return iterable<Schedule>
     */
    public function fetchSchedules(): iterable;

    /**
     * Get a list of all available channels
     *
     * @return iterable<Channel>
     */
    public function fetchChannels(): iterable;

    /**
     * Get a list of all available channel types
     *
     * @return iterable<AvailableChannelType>
     */
    public function fetchAvailableChannelTypes(): iterable;

    /**
     * Find a single contact by its username
     *
     * @param string $username
     *
     * @return ?Contact
     */
    public function findContactByUsername(string $username): ?Contact;

    /**
     * Find a single contact group by its name
     *
     * @param string $name
     * @param ?int $excludeId Exclude a specific contact group ID from the search
     *
     * @return ?Contactgroup
     */
    public function findContactGroupByName(string $name, ?int $excludeId = null): ?Contactgroup;

    /**
     * Find contacts whose ID is part of the given set
     *
     * @param int[] $ids
     *
     * @return iterable<Contact>
     */
    public function findContactsByIds(array $ids): iterable;
}
