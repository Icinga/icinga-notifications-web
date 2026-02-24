<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateInterval;
use DateTime;
use Generator;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;
use ipl\Web\Style;
use ipl\Web\Widget\Link;
use LogicException;
use SplObjectStorage;
use Traversable;

use function ipl\Stdlib\iterable_value_first;

/**
 * @phpstan-type GridArea array{0: int, 1: int, 2: int, 3: int}
 * @phpstan-import-type ContinuationType from Entry
 */
abstract class BaseGrid extends BaseHtmlElement
{
    use Translation;

    /** @var string The chronological order of entries is oriented horizontally */
    protected const HORIZONTAL_FLOW_OF_TIME = 'horizontal-flow';

    /** @var string The chronological order of entries is oriented vertically */
    protected const VERTICAL_FLOW_OF_TIME = 'vertical-flow';

    /** @var int Return this in {@see getSectionsPerStep} to signal an infinite number of sections */
    protected const INFINITE = 0;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => ['time-grid']];

    /** @var string The orientation of this grid's chronological order of entries */
    protected $flowOfTime = self::HORIZONTAL_FLOW_OF_TIME;

    /** @var EntryProvider */
    protected $provider;

    /** @var Style */
    protected $style;

    /** @var DateTime */
    protected $start;

    /** @var DateTime */
    protected $end;

    /** @var array Extra counts stored as [date1 => count1, date2 => count2]*/
    protected $extraEntriesCount = [];

    /**
     * Create a new time grid
     *
     * @param EntryProvider $provider The provider for the grid's entries
     * @param Style $style Required to place entries onto the grid's overlay
     * @param DateTime $start When the shown timespan should start
     */
    public function __construct(EntryProvider $provider, Style $style, DateTime $start)
    {
        $this->provider = $provider;
        $this->style = $style;
        $this->setGridStart($start);

        // It's done here as there's no real need for this being dynamic
        $this->defaultAttributes['class'][] = $this->flowOfTime;
    }

    public function getGridStart(): DateTime
    {
        return $this->start;
    }

    public function setGridStart(DateTime $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getGridEnd(): DateTime
    {
        if ($this->end === null) {
            $this->end = $this->calculateGridEnd();
        }

        return $this->end;
    }

    /**
     * Create steps to show on the grid
     *
     * @return Traversable<int, GridStep>
     */
    abstract protected function createGridSteps(): Traversable;

    abstract protected function calculateGridEnd(): DateTime;

    abstract protected function getNoOfVisuallyConnectedHours(): int;

    /**
     * Translate the given grid area positions suitable for the current grid
     *
     * @param int $rowStart
     * @param int $rowEnd
     * @param int $colStart
     * @param int $colEnd
     *
     * @return GridArea
     */
    protected function getGridArea(int $rowStart, int $rowEnd, int $colStart, int $colEnd): array
    {
        return [$rowStart, $colStart, $rowEnd, $colEnd];
    }

    protected function getSectionsPerStep(): int
    {
        return $this->getMaximumRowSpan();
    }

    protected function getMaximumRowSpan(): int
    {
        return 4;
    }

    protected function getRowStartModifier(): int
    {
        return 1; // CSS starts counting rows from 1, not zero
    }

    protected function createGrid(): BaseHtmlElement
    {
        $grid = new HtmlElement('div', Attributes::create(['class' => 'grid']));

        $this->assembleGrid($grid);

        return $grid;
    }

    protected function assembleGrid(BaseHtmlElement $grid): void
    {
        foreach ($this->createGridSteps() as $step) {
            $url = $this->provider->getStepUrl($step);
            if ($url !== null) {
                $step->setHtmlContent((new Link(null, $url))->openInModal()->addFrom($step));
            }

            if ($step->getEnd()->format('H') === '00') {
                $extraEntryUrl = $this->provider->getExtraEntryUrl($step);
                if ($extraEntryUrl !== null) {
                    $step->addHtml(
                        (new ExtraEntryCount(null, $extraEntryUrl))
                            ->setGrid($this)
                            ->setGridStep($step->getStart())
                    );
                }
            }

            $grid->addHtml($step);
        }
    }

    protected function createGridOverlay(): BaseHtmlElement
    {
        $overlay = new HtmlElement('div', Attributes::create(['class' => 'overlay']));

        $this->assembleGridOverlay($overlay);

        return $overlay;
    }

    /**
     * Fetch the count of additional entries for the given date
     *
     * @param DateTime $date
     *
     * @return int
     */
    public function getExtraEntryCount(DateTime $date): int
    {
        return $this->extraEntriesCount[$date->format('Y-m-d')] ?? 0;
    }

    /**
     * Yield the entries to show on the grid and place them using a flowing layout
     *
     * Entry positions are automatically calculated and can span multiple rows.
     * Collisions are prevented and the grid can have a limited number of sections.
     *
     * @param Traversable<int, Entry> $entries
     *
     * @return Generator<GridArea, Entry>
     */
    final protected function yieldFlowingEntries(Traversable $entries): Generator
    {
        $maxRowSpan = $this->getMaximumRowSpan();
        $sectionsPerStep = $this->getSectionsPerStep();
        $rowStartModifier = $this->getRowStartModifier();

        $infiniteSections = $sectionsPerStep === self::INFINITE;
        if ($infiniteSections) {
            $fillAvailableSpace = false;
        } else {
            // +1 because rows are 0-based here, but CSS grid rows are 1-based, hence the default modifier is 1
            $fillAvailableSpace = $maxRowSpan === ($sectionsPerStep - $rowStartModifier + 1);
        }

        $gridStartsAt = $this->getGridStart();
        $gridEndsAt = $this->getGridEnd();
        $amountOfDays = $gridStartsAt->diff($gridEndsAt)->days;
        $gridBorderAt = $this->getNoOfVisuallyConnectedHours() * 2;

        if ($infiniteSections && $amountOfDays !== $gridBorderAt / 48) {
            throw new LogicException(
                'The number of days in the grid must match the number of visually'
                . ' connected hours, when an infinite number of sections is used.'
            );
        }

        $cellOccupiers = [];
        /** @var SplObjectStorage<Entry, int[][]> $occupiedCells */
        $occupiedCells = new SplObjectStorage();
        foreach ($entries as $entry) {
            $actualStart = Util::roundToNearestThirtyMinute($entry->getStart());
            if ($actualStart < $gridStartsAt) {
                $entryStartPos = 0;
            } else {
                $entryStartPos = Util::diffHours($gridStartsAt, $actualStart) * 2;
            }

            $actualEnd = Util::roundToNearestThirtyMinute($entry->getEnd());
            if ($actualEnd > $gridEndsAt) {
                $entryEndPos = $amountOfDays * 48;
            } else {
                $entryEndPos = Util::diffHours($gridStartsAt, $actualEnd) * 2;
            }

            $rows = [];
            for ($i = $entryStartPos; $i < $entryEndPos && $i < $amountOfDays * 48; $i++) {
                $row = (int) floor($i / $gridBorderAt);
                $column = $i % $gridBorderAt;
                $rowStart = $row * $sectionsPerStep;
                $rows[$rowStart][] = $column;
                $cellOccupiers[$rowStart][$column][] = spl_object_id($entry);
            }

            $occupiedCells->attach($entry, $rows);
        }

        $rowPlacements = [];
        foreach ($cellOccupiers as $row => $columns) {
            foreach ($columns as $occupiers) {
                foreach ($occupiers as $id) {
                    if (isset($rowPlacements[$id][$row])) {
                        // Ignore already placed rows for now, they may be moved separately below though
                        continue;
                    }

                    $rowStart = $row + $rowStartModifier;
                    $rowSpan = $maxRowSpan;

                    $competingOccupiers = array_filter($occupiers, function ($id) use ($rowPlacements, $row) {
                        return isset($rowPlacements[$id][$row]);
                    });
                    usort($competingOccupiers, function ($id, $otherId) use ($rowPlacements, $row) {
                        return $rowPlacements[$id][$row][0] <=> $rowPlacements[$otherId][$row][0];
                    });

                    foreach ($competingOccupiers as $otherId) {
                        list($otherRowStart, $otherRowSpan) = $rowPlacements[$otherId][$row];
                        if ($otherRowStart === $rowStart) {
                            if ($fillAvailableSpace) {
                                $otherRowSpan = (int) ceil($otherRowSpan / 2);
                                $rowStart += $otherRowSpan;
                                $rowSpan -= $otherRowSpan;
                                $rowPlacements[$otherId][$row] = [$otherRowStart, $otherRowSpan];
                            } else {
                                $rowStart += $maxRowSpan;
                            }
                        } elseif ($fillAvailableSpace) {
                            $rowSpan = $otherRowStart - $rowStart;
                            break; // It occupies space now that was already reserved, so it should be safe to use
                        }
                    }

                    $rowPlacements[$id][$row] = [$rowStart, $rowSpan];
                }
            }
        }

        $this->extraEntriesCount = [];
        foreach ($occupiedCells as $entry) {
            $continuationType = null;
            $rows = $occupiedCells->getInfo();
            $fromPrevGrid = $gridStartsAt > $entry->getStart();
            $remainingRows = count($rows);
            $toNextGrid = false;

            foreach ($rows as $row => $hours) {
                list($rowStart, $rowSpan) = $rowPlacements[spl_object_id($entry)][$row];
                $colStart = min($hours);
                $colEnd = max($hours);

                // Calculate number of entries that are not displayed in the grid for each date
                if (! $infiniteSections && $rowStart > $row + $sectionsPerStep) {
                    $startOffset = (int) (($row / $sectionsPerStep) * ($gridBorderAt / 48) + $colStart / 48);
                    $endOffset = (int) (($row / $sectionsPerStep) * ($gridBorderAt / 48) + $colEnd / 48);
                    $startDate = (clone $this->getGridStart())->add(new DateInterval("P$startOffset" . 'D'));
                    $duration = $endOffset - $startOffset;
                    for ($i = 0; $i <= $duration; $i++) {
                        $countIdx = $startDate->format('Y-m-d');
                        if (! isset($this->extraEntriesCount[$countIdx])) {
                            $this->extraEntriesCount[$countIdx] = 1;
                        } else {
                            $this->extraEntriesCount[$countIdx] += 1;
                        }

                        $startDate->add(new DateInterval('P1D'));
                    }

                    continue;
                }

                $gridArea = $this->getGridArea(
                    $rowStart,
                    $rowStart + $rowSpan,
                    $colStart + 1,
                    $colEnd + 2
                );

                $isLastRow = $remainingRows === 1;
                if ($isLastRow) {
                    $toNextGrid = $gridEndsAt < $entry->getEnd();
                }

                $backward = $continuationType || $fromPrevGrid;
                $forward = ! $isLastRow || $toNextGrid;
                if ($backward && $forward) {
                    $entry->setContinuationType(Entry::ACROSS_BOTH_EDGES);
                } elseif ($backward) {
                    $entry->setContinuationType(Entry::ACROSS_LEFT_EDGE);
                } elseif ($forward) {
                    $entry->setContinuationType(Entry::ACROSS_RIGHT_EDGE);
                } elseif ($fromPrevGrid && $toNextGrid) {
                    $entry->setContinuationType(Entry::ACROSS_GRID);
                } elseif ($fromPrevGrid) {
                    $entry->setContinuationType(Entry::FROM_PREV_GRID);
                } elseif ($toNextGrid) {
                    $entry->setContinuationType(Entry::TO_NEXT_GRID);
                }

                yield $gridArea => $entry;

                $continuationType = $entry->getContinuationType();
                $fromPrevGrid = false;
                $remainingRows -= 1;
            }
        }

        if ($infiniteSections) {
            $lastRow = array_reduce($rowPlacements, function ($carry, $placements) {
                return array_reduce($placements, function ($carry, $placement) {
                    return max($placement[0] + $placement[1], $carry);
                }, $carry);
            }, 1);

            $this->style->addFor($this, [
                '--primaryRows' => $lastRow === 1 ? 1 : ($lastRow - $rowStartModifier) / $maxRowSpan,
                '--rowsPerStep' => $maxRowSpan
            ]);
        }
    }

    /**
     * Yield the entries to show on the grid and place them using a fixed layout
     *
     * Entry positions are expected to be registered on each individual entry and cannot span multiple rows.
     * Collisions won't be prevented and the grid is expected to allow for an infinite number of sections.
     *
     * @param Traversable<int, Entry> $entries
     *
     * @return Generator<GridArea, Entry>
     */
    final protected function yieldFixedEntries(Traversable $entries): Generator
    {
        if ($this->getMaximumRowSpan() !== 1) {
            throw new LogicException('Fixed layouts require a maximum row span of 1');
        }

        if ($this->getSectionsPerStep() !== self::INFINITE) {
            throw new LogicException('Fixed layouts currently only work with an infinite number of sections');
        }

        $rowStartModifier = $this->getRowStartModifier();
        $gridStartsAt = $this->getGridStart();
        $gridEndsAt = $this->getGridEnd();
        $amountOfDays = $gridStartsAt->diff($gridEndsAt)->days;
        $gridBorderAt = $this->getNoOfVisuallyConnectedHours() * 2;

        if ($amountOfDays !== $gridBorderAt / 48) {
            throw new LogicException(
                'The number of days in the grid must match the number'
                . ' of visually connected hours, when a fixed layout is used.'
            );
        }

        $lastRow = 1;
        foreach ($entries as $entry) {
            $position = $entry->getPosition();
            if ($position === null) {
                throw new LogicException('All entries must have a position set when using a fixed layout');
            }

            $rowStart = $position + $rowStartModifier;
            if ($rowStart > $lastRow) {
                $lastRow = $rowStart;
            }

            $actualStart = Util::roundToNearestThirtyMinute($entry->getStart());
            if ($actualStart < $gridStartsAt) {
                $colStart = 0;
            } else {
                $colStart = Util::diffHours($gridStartsAt, $actualStart) * 2;
            }

            $actualEnd = Util::roundToNearestThirtyMinute($entry->getEnd());
            if ($actualEnd > $gridEndsAt) {
                $colEnd = $gridBorderAt;
            } else {
                $colEnd = Util::diffHours($gridStartsAt, $actualEnd) * 2;
            }

            if ($colStart > $gridBorderAt || $colEnd === $colStart) {
                throw new LogicException(sprintf(
                    'Invalid entry (%d) position: %s to %s. Grid dimension: %s to %s',
                    $entry->getId(),
                    $actualStart->format('Y-m-d H:i:s'),
                    $actualEnd->format('Y-m-d H:i:s'),
                    $gridStartsAt->format('Y-m-d'),
                    $gridEndsAt->format('Y-m-d')
                ));
            }

            $gridArea = $this->getGridArea(
                $rowStart,
                $rowStart + 1,
                $colStart + 1,
                $colEnd + 1
            );

            $fromPrevGrid = $gridStartsAt > $entry->getStart();
            $toNextGrid = $gridEndsAt < $entry->getEnd();
            if ($fromPrevGrid && $toNextGrid) {
                $entry->setContinuationType(Entry::ACROSS_GRID);
            } elseif ($fromPrevGrid) {
                $entry->setContinuationType(Entry::FROM_PREV_GRID);
            } elseif ($toNextGrid) {
                $entry->setContinuationType(Entry::TO_NEXT_GRID);
            }

            yield $gridArea => $entry;
        }

        $this->style->addFor($this, [
            '--primaryRows' => $lastRow === 1 ? 1 : $lastRow - $rowStartModifier + 1,
            '--rowsPerStep' => 1
        ]);
    }

    protected function assembleGridOverlay(BaseHtmlElement $overlay): void
    {
        $entries = $this->provider->getEntries();
        $firstEntry = iterable_value_first($entries);
        if ($firstEntry === null) {
            return;
        }

        if ($firstEntry->getPosition() === null) {
            $generator = $this->yieldFlowingEntries($entries);
        } else {
            $generator = $this->yieldFixedEntries($entries);
        }

        foreach ($generator as $gridArea => $entry) {
            $this->style->addFor($entry, [
                '--entry-bg' => $entry->getColor(10),
                '--entry-border-color' => $entry->getColor(50),
                'grid-area' => sprintf('~"%d / %d / %d / %d"', ...$gridArea)
            ]);

            $overlay->addHtml($entry);
        }
    }
}
