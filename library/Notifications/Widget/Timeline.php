<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Widget\Calendar\DynamicGrid;
use Icinga\Module\Notifications\Widget\Calendar\Entry;
use Icinga\Module\Notifications\Widget\Calendar\EntryProvider;
use Icinga\Module\Notifications\Widget\Calendar\GridStep;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Scheduler\RRule;
use ipl\Web\Style;
use ipl\Web\Url;
use SplObjectStorage;
use Traversable;

class Timeline extends BaseHtmlElement implements EntryProvider
{
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => ['timeline']];

    /** @var array<int, Rotation> */
    protected $rotations = [];

    /** @var DateTime */
    protected $start;

    /** @var int */
    protected $days;

    /** @var Style */
    protected $style;

    /** @var ?DynamicGrid */
    protected $grid;

    /**
     * Set the style object to register inline styles in
     *
     * @param Style $style
     *
     * @return $this
     */
    public function setStyle(Style $style): self
    {
        $this->style = $style;

        return $this;
    }

    /**
     * Get the style object to register inline styles in
     *
     * @return Style
     */
    public function getStyle(): Style
    {
        if ($this->style === null) {
            $this->style = new Style();
        }

        return $this->style;
    }

    /**
     * Create a new Timeline
     *
     * @param DateTime $start The day the grid should start on
     * @param int $days Number of days to show on the grid
     */
    public function __construct(DateTime $start, int $days)
    {
        $this->start = $start;
        $this->days = $days;
    }

    /**
     * Add a rotation to the timeline
     *
     * @param Rotation $rotation
     *
     * @return void
     */
    public function addRotation(Rotation $rotation): void
    {
        $this->rotations[] = $rotation;
    }

    public function getStepUrl(GridStep $step): ?Url
    {
        return null;
    }

    public function getExtraEntryUrl(GridStep $step): ?Url
    {
        return null;
    }

    public function getEntries(): Traversable
    {
        $rotations = $this->rotations;
        // Rotations are not necessarily sorted by priority yet
        usort($rotations, function (Rotation $a, Rotation $b) {
            return $a->getPriority() <=> $b->getPriority();
        });

        $getDesiredCells = function (Entry $e) {
            if ($e->getStart() <= $this->start) {
                $actualStart = $this->start->getTimestamp();
                $cellStart = 0;
            } else {
                $actualStart = $e->getStart()->getTimestamp();
                $cellStart = ($actualStart - $this->start->getTimestamp()) / 1800;
            }

            if ($e->getEnd() > $this->getGrid()->getGridEnd()) {
                $actualEnd = $this->getGrid()->getGridEnd()->getTimestamp();
            } else {
                $actualEnd = $e->getEnd()->getTimestamp();
            }

            $numberOfRequiredCells = ($actualEnd - $actualStart) / 1800;
            if ($numberOfRequiredCells < 1) {
                return [];
            }

            return array_fill((int) $cellStart, (int) $numberOfRequiredCells, $e);
        };

        $maxPriority = array_reduce($rotations, function (int $carry, Rotation $rotation) {
            return max($carry, $rotation->getPriority());
        }, 0);

        $occupiedCells = [];
        $resultPosition = $maxPriority + 1;
        foreach ($rotations as $rotation) {
            $actualHandoff = $rotation->getActualHandoff();
            $rotationPosition = $maxPriority - $rotation->getPriority();
            foreach ($rotation->fetchTimeperiodEntries($this->start) as $entry) {
                $rrule = $entry->getRecurrencyRule();
                $start = $entry->getStart();
                $end = $entry->getEnd();

                $entry->setPosition($rotationPosition);

                if ($rrule) {
                    $grid = $this->getGrid();
                    $rrule = new RRule($rrule);
                    // TODO: Calculate the nearest start possible, where the rotations restarts
                    $rrule->startAt($entry->getStart());
                    $length = $start->diff($end);

                    $limit = 31; // Mandatory, 1 otherwise, TODO: the rotation should provide a suitable limit
                    $recurrenceStart = (clone $grid->getGridStart())->sub($length);
                    foreach ($rrule->getNextRecurrences($recurrenceStart, $limit) as $recurrence) {
                        $recurrenceEnd = (clone $recurrence)->add($length);

                        if ($recurrence < $actualHandoff && $recurrenceEnd > $actualHandoff) {
                            $recurrence = $actualHandoff;
                        }

                        $occurrence = (new Entry($entry->getId()))
                            ->setIsOccurrence()
                            ->setStart($recurrence)
                            ->setEnd($recurrenceEnd)
                            ->setUrl($entry->getUrl())
                            ->setAttendee($entry->getAttendee())
                            ->setPosition($entry->getPosition());

                        yield $occurrence;

                        $occupiedCells += $getDesiredCells($occurrence);
                    }
                } else {
                    if ($start < $actualHandoff && $end > $actualHandoff) {
                        $entry->setStart($actualHandoff);
                    }

                    yield $entry;

                    $occupiedCells += $getDesiredCells($entry);
                }
            }
        }

        $entryToCellsMap = new SplObjectStorage();
        foreach ($occupiedCells as $cell => $entry) {
            $cells = $entryToCellsMap[$entry] ?? [];
            $cells[] = $cell;
            $entryToCellsMap->attach($entry, $cells);
        }

        foreach ($entryToCellsMap as $entry) {
            $cells = $entryToCellsMap->getInfo();

            $firstCell = null;
            $previousCell = null;

            do {
                $cell = array_shift($cells);
                if ($firstCell === null && ! empty($cells)) {
                    $firstCell = $cell;
                } elseif (empty($cells) || $cell - $previousCell > 1) {
                    $start = (clone $this->start)->add(
                        new DateInterval(sprintf('PT%dS', ($firstCell ?? $cell) * 1800))
                    );
                    if ($start == $this->getGrid()->getGridStart()) {
                        $start = $entry->getStart();
                    }

                    $lastCell = empty($cells) ? $cell : $previousCell;

                    $end = (clone $this->start)->add(
                        new DateInterval(sprintf('PT%dS', ++$lastCell * 1800))
                    );
                    if ($end == $this->getGrid()->getGridEnd()) {
                        $end = $entry->getEnd();
                    }

                    yield (new Entry($entry->getId()))
                        ->setStart($start)
                        ->setEnd($end)
                        ->setUrl($entry->getUrl())
                        ->setPosition($resultPosition)
                        ->setAttendee($entry->getAttendee());

                    $firstCell = $cell;
                }

                $previousCell = $cell;
            } while (! empty($cells));
        }
    }

    /**
     * Get the grid for this timeline
     *
     * @return DynamicGrid
     */
    protected function getGrid(): DynamicGrid
    {
        if ($this->grid === null) {
            $this->grid = new DynamicGrid($this, $this->getStyle(), $this->start);
            $this->grid->setDays($this->days);

            $rotations = $this->rotations;
            usort($rotations, function (Rotation $a, Rotation $b) {
                return $b->getPriority() <=> $a->getPriority();
            });
            $occupiedPriorities = [];
            foreach ($rotations as $rotation) {
                if (! isset($occupiedPriorities[$rotation->getPriority()])) {
                    $occupiedPriorities[$rotation->getPriority()] = true;
                    $this->grid->addToSideBar(new HtmlElement(
                        'div',
                        Attributes::create(['class' => 'rotation-name']),
                        new HtmlElement('span', null, Text::create($rotation->getName()))
                    ));
                }
            }
        }

        return $this->grid;
    }

    protected function assemble()
    {
        if (empty($this->rotations)) {
            $this->getGrid()->addToSideBar(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => 'empty-notice']),
                    Text::create($this->translate('No rotations configured'))
                )
            );
        }

        $this->getGrid()->addToSideBar(
            new HtmlElement(
                'div',
                null,
                Text::create($this->translate('Result'))
            )
        );

        $this->addHtml(
            $this->getGrid(),
            $this->getStyle()
        );
    }
}
