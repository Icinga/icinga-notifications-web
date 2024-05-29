<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateTime;
use DateTimeInterface;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;

/**
 * The visual representation of a step on the grid
 */
class GridStep extends BaseHtmlElement
{
    /** @var DateTime Start time of the grid step */
    protected $start;

    /** @var DateTime End time of the grid step */
    protected $end;

    /** @var array{int, int} The x and y position of the step on the grid */
    protected $coordinates;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'step'];

    /**
     * Create a new grid step
     *
     * @param DateTime $start The start time of the grid step
     * @param DateTime $end The end time of the grid step
     * @param int $x The x position of the step on the grid
     * @param int $y The y position of the step on the grid
     */
    public function __construct(DateTime $start, DateTime $end, int $x, int $y)
    {
        $this->start = $start;
        $this->end = $end;
        $this->coordinates = [$x, $y];
    }

    /**
     * Get the start time of the grid step
     *
     * @return DateTime
     */
    public function getStart(): DateTime
    {
        return $this->start;
    }

    /**
     * Get the end time of the grid step
     *
     * @return DateTime
     */
    public function getEnd(): DateTime
    {
        return $this->end;
    }

    /**
     * Get the coordinates of the grid step
     *
     * @return array{int, int} The x and y position of the step on the grid
     */
    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    protected function registerAttributeCallbacks(Attributes $attributes)
    {
        $this->getAttributes()->registerAttributeCallback('data-start', function () {
            return $this->getStart()->format(DateTimeInterface::ATOM);
        });
    }
}
