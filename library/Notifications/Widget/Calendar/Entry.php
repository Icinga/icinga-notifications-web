<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateTime;
use ipl\Web\Url;

class Entry
{
    protected $id;

    protected $description;

    protected $start;

    protected $end;

    /** @var ?int The 0-based position of the row where to place this entry on the grid */
    protected $position;

    protected $rrule;

    /** @var Url */
    protected $url;

    protected $isOccurrence = false;

    /** @var Attendee */
    protected $attendee;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setStart(DateTime $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getStart(): ?DateTime
    {
        return $this->start;
    }

    public function setEnd(DateTime $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function getEnd(): ?DateTime
    {
        return $this->end;
    }

    /**
     * Set the position of the row where to place this entry on the grid
     *
     * @param ?int $position The 0-based position of the row
     *
     * @return $this
     */
    public function setPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Get the position of the row where to place this entry on the grid
     *
     * @return ?int The 0-based position of the row
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setRecurrencyRule(?string $rrule): self
    {
        $this->rrule = $rrule;

        return $this;
    }

    public function getRecurrencyRule(): ?string
    {
        return $this->rrule;
    }

    public function setIsOccurrence(bool $state = true): self
    {
        $this->isOccurrence = $state;

        return $this;
    }

    public function isOccurrence(): bool
    {
        return $this->isOccurrence;
    }

    public function setUrl(?Url $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl(): ?Url
    {
        return $this->url;
    }

    public function setAttendee(Attendee $attendee): self
    {
        $this->attendee = $attendee;

        return $this;
    }

    public function getAttendee(): Attendee
    {
        return $this->attendee;
    }
}
