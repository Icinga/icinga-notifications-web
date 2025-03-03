<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\MoveRotationForm;
use Icinga\Module\Notifications\Widget\TimeGrid\DynamicGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\EntryProvider;
use Icinga\Module\Notifications\Widget\TimeGrid\GridStep;
use Icinga\Module\Notifications\Widget\Timeline\Entry;
use Icinga\Module\Notifications\Widget\Timeline\MinimalGrid;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Style;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
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

    /** @var ?DynamicGrid|MinimalGrid */
    protected $grid;

    /** @var bool Whether to create the Timeline only with the Result using MinimalGrid */
    protected $minimalLayout = false;

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
     * Set whether to create the Timeline only with the Result
     *
     * @return $this
     */
    public function minimalLayout(): self
    {
        $this->minimalLayout = true;

        return $this;
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

        $resultPosition = 0;
        $maxPriority = 0;

        if (! $this->minimalLayout) {
            $maxPriority = array_reduce($rotations, function (int $carry, Rotation $rotation) {
                return max($carry, $rotation->getPriority());
            }, 0);
            $resultPosition = $maxPriority + 1;
        }

        $occupiedCells = [];
        foreach ($rotations as $rotation) {
            foreach ($rotation->fetchTimeperiodEntries($this->start, $this->getGrid()->getGridEnd()) as $entry) {
                if (! $this->minimalLayout) {
                    $entry->setPosition($maxPriority - $rotation->getPriority());

                    yield $entry;
                }

                $occupiedCells += $getDesiredCells($entry);
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

                    $resultEntry = (new Entry($entry->getId()))
                        ->setStart($start)
                        ->setEnd($end)
                        ->setMember($entry->getMember());

                    if (! $this->minimalLayout) {
                        $resultEntry->setPosition($resultPosition);
                        $resultEntry->setUrl($entry->getUrl());
                        $resultEntry->getAttributes()
                            ->add('data-rotation-position', $entry->getPosition());
                    }

                    yield $resultEntry;

                    $firstCell = $cell;
                }

                $previousCell = $cell;
            } while (! empty($cells));
        }
    }

    /**
     * Get the grid for this timeline
     *
     * @return DynamicGrid|MinimalGrid
     */
    protected function getGrid()
    {
        if ($this->grid === null) {
            if ($this->minimalLayout) {
                $this->grid = new MinimalGrid($this, $this->getStyle(), $this->start);
            } else {
                $this->grid = (new DynamicGrid($this, $this->getStyle(), $this->start))->setDays($this->days);
            }

            if (! $this->minimalLayout) {
                $rotations = $this->rotations;
                usort($rotations, function (Rotation $a, Rotation $b) {
                    return $b->getPriority() <=> $a->getPriority();
                });
                $occupiedPriorities = [];
                foreach ($rotations as $rotation) {
                    if (! isset($occupiedPriorities[$rotation->getPriority()])) {
                        $occupiedPriorities[$rotation->getPriority()] = true;
                        $this->grid->addToSideBar($this->assembleSidebarEntry($rotation));
                    }
                }
            }
        }

        return $this->grid;
    }

    protected function assembleSidebarEntry(Rotation $rotation): BaseHtmlElement
    {
        $entry = new HtmlElement('div', Attributes::create(['class' => 'rotation-name']));

        $form = new MoveRotationForm();
        $form->setAction(Links::moveRotation()->getAbsoluteUrl());
        $form->populate([
            'rotation' => $rotation->getId(),
            'priority' => $rotation->getPriority()
        ]);

        $entry->addHtml(
            $form,
            new Icon('bars', ['data-drag-initiator' => true]),
            (new Link(
                [new HtmlElement('span', null, Text::create($rotation->getName())), new Icon('cog')],
                Links::rotationSettings($rotation->getId(), $rotation->getScheduleId())
            ))->openInModal()
        );

        return $entry;
    }

    protected function assemble()
    {
        if (empty($this->rotations)) {
            $emptyNotice = new HtmlElement(
                'div',
                Attributes::create(['class' => 'empty-notice']),
                Text::create($this->translate('No rotations configured'))
            );

            if ($this->minimalLayout) {
                $this->getAttributes()->add(['class' => 'minimal-layout']);
                $this->addHtml($emptyNotice);
            } else {
                $this->getGrid()->addToSideBar($emptyNotice);
            }
        }

        if (! $this->minimalLayout) {
            $this->getGrid()->addToSideBar(
                new HtmlElement(
                    'div',
                    null,
                    Text::create($this->translate('Result'))
                )
            );
        }

        $this->addHtml(
            $this->getGrid(),
            $this->getStyle()
        );
    }
}
