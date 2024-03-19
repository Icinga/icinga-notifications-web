<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Common;

trait NoSubjectLink
{
    /** @var bool */
    protected $noSubjectLink = false;

    /**
     * Set whether a list item's subject should be a link
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setNoSubjectLink(bool $state = true): self
    {
        $this->noSubjectLink = $state;

        return $this;
    }

    /**
     * Get whether a list item's subject should be a link
     *
     * @return bool
     */
    public function getNoSubjectLink(): bool
    {
        return $this->noSubjectLink;
    }
}
