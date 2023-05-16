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
