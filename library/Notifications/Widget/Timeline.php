<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\MoveRotationForm;
use Icinga\Module\Notifications\Widget\TimeGrid\DynamicGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\EntryProvider;
use Icinga\Module\Notifications\Widget\TimeGrid\GridStep;
use Icinga\Module\Notifications\Widget\TimeGrid\Timescale;
use Icinga\Module\Notifications\Widget\TimeGrid\Util;
use Icinga\Module\Notifications\Widget\Timeline\Entry;
use Icinga\Module\Notifications\Widget\Timeline\FakeEntry;
use Icinga\Module\Notifications\Widget\Timeline\FutureEntry;
use Icinga\Module\Notifications\Widget\Timeline\MinimalGrid;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\TemplateString;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Style;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use Locale;
use SplObjectStorage;
use Traversable;

class Timeline extends BaseHtmlElement implements EntryProvider
{
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => ['timeline']];

    /** @var array<int, Rotation> */
    protected $rotations = [];

    /** @var int */
    protected int $scheduleId;

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

    /** @var int */
    protected int $noOfRotations = 0;

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
     * @param int $scheduleId The schedule ID
     * @param DateTime $start The day the grid should start on
     * @param int $days Number of days to show on the grid
     */
    public function __construct(int $scheduleId, DateTime $start, int $days)
    {
        $this->scheduleId = $scheduleId;
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
            $entryFound = false;
            if (! $this->minimalLayout) {
                $flyoutInfo = $rotation->generateEntryInfo($this->start->getTimezone());
            }

            foreach ($rotation->fetchTimeperiodEntries($this->start, $this->getGrid()->getGridEnd()) as $entry) {
                $entryFound = true;
                if (! $this->minimalLayout) {
                    $entry->setPosition($maxPriority - $rotation->getPriority());
                    $entry->setFlyoutContent($flyoutInfo);
                    $entry->calculateAndSetWidthClass($this->getGrid());

                    yield $entry;
                }

                $occupiedCells += $getDesiredCells($entry);
            }

            if (! $entryFound && ! $this->minimalLayout) {
                yield (new FutureEntry())
                    ->setStart($this->getGrid()->getGridStart())
                    ->setEnd($this->getGrid()->getGridEnd())
                    ->setPosition($maxPriority - $rotation->getPriority());
            }
        }

        if (! $this->minimalLayout) {
            // Always yield a fake entry to reserve the position for the add-rotation button
            yield (new FakeEntry())
                ->setPosition($resultPosition++)
                ->setStart($this->getGrid()->getGridStart())
                ->setEnd($this->getGrid()->getGridEnd());
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
                        $resultEntry->setScheduleTimezone($entry->getScheduleTimezone());
                        $resultEntry->setFlyoutContent($entry->getFlyoutContent())
                            ->calculateAndSetWidthClass($this->getGrid());
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
                        $this->noOfRotations++;
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

        $dragInitiator = new Icon('bars', [
            'title' => $this->translate('Drag to change the priority of the rotation')
        ]);
        $dragInitiator
            ->getAttributes()
            ->registerAttributeCallback('data-drag-initiator', fn () => $this->noOfRotations > 1);

        $entry->addHtml(
            $form,
            $dragInitiator,
            (new Link(
                [new HtmlElement('span', null, Text::create($rotation->getName())), new Icon('cog')],
                Links::rotationSettings($rotation->getId(), $rotation->getScheduleId())
            ))->openInModal()
        );

        return $entry;
    }

    protected function assemble()
    {
        if ($this->minimalLayout && empty($this->rotations)) {
            $this->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'empty-notice']),
                Text::create($this->translate('No rotations configured'))
            ));
        }

        if (! $this->minimalLayout) {
            // We yield a fake overlay entry, so we also have to fake a sidebar entry
            $this->getGrid()->addToSideBar(new HtmlElement('div'));

            $this->getGrid()->addToSideBar(
                new HtmlElement(
                    'div',
                    null,
                    Text::create($this->translate('Result'))
                )
            );

            $displayTimezone = $this->start->getTimezone();

            $dateFormatter = new IntlDateFormatter(
                Locale::getDefault(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::SHORT,
                $displayTimezone
            );

            $now = new DateTime('now', $displayTimezone);
            $currentTime = new HtmlElement(
                'div',
                new Attributes(['class' => 'time-hand']),
                new HtmlElement(
                    'div',
                    new Attributes(['class' => 'now', 'title' => $dateFormatter->format($now)]),
                    Text::create($this->translate('now'))
                )
            );

            $now = Util::roundToNearestThirtyMinute($now);
            $diff = $this->start->diff($now);

            $this->getStyle()->addFor($currentTime, [
                '--timeStartColumn' =>
                    ($diff->d * 24 + $diff->h) * 2 // 2 columns per hour
                    + ($diff->i >= 30 ? 1 : 0) // 1 column for the half hour
                    + 1 // CSS starts counting columns from 1, not zero
            ]);

            $clock = new HtmlElement(
                'div',
                new Attributes(['class' => 'clock']),
                new HtmlElement('div', new Attributes(['class' => 'current-day']), $currentTime)
            );

            if (empty($this->rotations)) {
                $newRotationMsg = $this->translate(
                    'No rotations configured, yet. {{#button}}Add your first Rotation{{/button}}'
                );
            } else {
                $newRotationMsg = $this->translate(
                    '{{#button}}Add another Rotation{{/button}} to override rotations above'
                );
            }

            $this->getGrid()
                ->addHtml(new HtmlElement(
                    'div',
                    new Attributes(['class' => 'new-rotation-container']),
                    new HtmlElement(
                        'div',
                        Attributes::create(['class' => 'new-rotation-content']),
                        new HtmlElement(
                            'span',
                            null,
                            TemplateString::create(
                                $newRotationMsg,
                                [
                                    'button' => (new Link(
                                        new Icon('circle-plus'),
                                        Links::rotationAdd($this->scheduleId),
                                        ['class' => empty($this->rotations) ? 'btn-primary' : null]
                                    ))->openInModal()
                                ]
                            )
                        )
                    )
                ))
                ->addHtml(new Timescale($this->days, $this->getStyle()))
                ->addHtml($clock);
        }

        $this->addHtml(
            $this->getGrid(),
            $this->getStyle()
        );
    }
}
