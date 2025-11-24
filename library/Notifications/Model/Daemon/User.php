<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model\Daemon;

class User
{
    /** @var ?string Username */
    protected ?string $username = null;

    /** @var ?int Contact identifier */
    protected ?int $contactId = null;

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function setContactId(int $contactId): void
    {
        $this->contactId = $contactId;
    }
}
