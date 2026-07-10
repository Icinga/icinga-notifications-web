<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form;

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
}
