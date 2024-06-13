<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model\Daemon;

class User
{
    /** @var ?string Username */
    protected $username;

    /** @var ?int Contact identifier */
    protected $contactId;

    public function __construct()
    {
        $this->username = null;
        $this->contactId = null;
    }

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
