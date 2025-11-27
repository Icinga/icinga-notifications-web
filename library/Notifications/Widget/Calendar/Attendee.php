<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use InvalidArgumentException;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\Icon;

/**
 * An attendee of a calendar entry
 */
class Attendee
{
    /** @var string */
    protected string $name;

    /** @var string|ValidHtml */
    protected string|ValidHtml $icon = 'user';

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setIcon($icon): self
    {
        if ($icon === null) {
            throw new InvalidArgumentException('Cannot unset icon');
        }

        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ValidHtml
    {
        if (is_string($this->icon)) {
            $icon = new Icon($this->icon);
        } else {
            $icon = $this->icon;
        }

        return $icon;
    }
}
