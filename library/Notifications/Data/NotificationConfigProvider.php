<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Data;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;

class NotificationConfigProvider implements ConfigProviderInterface
{
    private ?ResultSet $contacts = null;

    private ?ResultSet $contactGroups = null;

    private ?ResultSet $schedules = null;

    private ?ResultSet $channels = null;

    private ?ResultSet $availableChannelTypes = null;

    public function fetchContacts(): iterable
    {
        if ($this->contacts === null) {
            $this->contacts = Contact::on(Database::get())
                ->execute();
        }

        return $this->contacts;
    }

    public function fetchContactGroups(): iterable
    {
        if ($this->contactGroups === null) {
            $this->contactGroups = Contactgroup::on(Database::get())
                ->execute();
        }

        return $this->contactGroups;
    }

    public function fetchSchedules(): iterable
    {
        if ($this->schedules === null) {
            $this->schedules = Schedule::on(Database::get())
                ->execute();
        }

        return $this->schedules;
    }

    public function fetchChannels(): iterable
    {
        if ($this->channels === null) {
            $this->channels = Channel::on(Database::get())
                ->execute();
        }

        return $this->channels;
    }

    public function fetchAvailableChannelTypes(): iterable
    {
        if ($this->availableChannelTypes === null) {
            $this->availableChannelTypes = AvailableChannelType::on(Database::get())
                ->execute();
        }

        return $this->availableChannelTypes;
    }

    public function findContactByUsername(string $username): ?Contact
    {
        return Contact::on(Database::get())
            ->filter(Filter::equal('username', $username))
            ->first();
    }

    public function findContactGroupByName(string $name, ?int $excludeId = null): ?Contactgroup
    {
        $query = Contactgroup::on(Database::get())
            ->filter(Filter::equal('name', $name));
        if ($excludeId !== null) {
            $query->filter(Filter::unequal('id', $excludeId));
        }

        return $query->first();
    }

    public function findContactsByIds(array $ids): iterable
    {
        return Contact::on(Database::get())
            ->filter(Filter::equal('id', $ids))
            ->execute();
    }
}
