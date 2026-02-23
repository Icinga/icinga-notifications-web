<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Orm\ResultSet;

class NotificationConfigProvider implements ConfigProviderInterface
{
    private ?ResultSet $contacts = null;

    private ?ResultSet $contactGroups = null;

    private ?ResultSet $schedules = null;

    private ?ResultSet $channels = null;

    public function fetchContacts(): iterable
    {
        if ($this->contacts === null) {
            $this->contacts = Contact::on(Database::get())
                ->columns(['id', 'full_name'])
                ->execute();
        }

        return $this->contacts;
    }

    public function fetchContactGroups(): iterable
    {
        if ($this->contactGroups === null) {
            $this->contactGroups = Contactgroup::on(Database::get())
                ->columns(['id', 'name'])
                ->execute();
        }

        return $this->contactGroups;
    }

    public function fetchSchedules(): iterable
    {
        if ($this->schedules === null) {
            $this->schedules = Schedule::on(Database::get())
                ->columns(['id', 'name'])
                ->execute();
        }

        return $this->schedules;
    }

    public function fetchChannels(): iterable
    {
        if ($this->channels === null) {
            $this->channels = Channel::on(Database::get())
                ->columns(['id', 'name'])
                ->execute();
        }

        return $this->channels;
    }
}
