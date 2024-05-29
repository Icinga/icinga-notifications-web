<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use ipl\Html\BaseHtmlElement;
use Icinga\Module\Notifications\Widget\TimeGrid;

class Entry extends TimeGrid\Entry
{
    /** @var Member */
    protected $member;

    public function setMember(Member $member): self
    {
        $this->member = $member;

        return $this;
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function getColor(int $transparency): string
    {
        return TimeGrid\Util::calculateEntryColor($this->getMember()->getName(), $transparency);
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        // TODO: Implement assembleContainer() method.
    }
}
