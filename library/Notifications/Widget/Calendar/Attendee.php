<?php

namespace Icinga\Module\Noma\Widget\Calendar;

use InvalidArgumentException;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\Icon;

class Attendee
{
    /** @var string */
    protected $name;

    /** @var string|ValidHtml */
    protected $icon = 'user';

    /** @var string */
    protected $color = '';

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

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }
}
