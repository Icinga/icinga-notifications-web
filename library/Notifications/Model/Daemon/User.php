<?php

namespace Icinga\Module\Notifications\Model\Daemon;

final class User
{
    /**
     * @var ?string $username
     */
    private $username;

    /**
     * @var ?int $contactId
     */
    private $contactId;

    public function __construct()
    {
        $this->username = null;
        $this->contactId = null;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    public function setContactId(int $contactId): void
    {
        $this->contactId = $contactId;
    }
}
