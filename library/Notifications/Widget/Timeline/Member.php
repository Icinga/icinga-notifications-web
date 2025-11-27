<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

/**
 * A member of a timeline entry
 */
class Member
{
    /** @var string */
    protected string $name;

    /** @var string */
    protected string $icon = 'user';

    /**
     * Create a new Member
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get the name of the member
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the icon
     *
     * @param string $icon
     *
     * @return $this
     */
    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Get the icon
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->icon;
    }
}
