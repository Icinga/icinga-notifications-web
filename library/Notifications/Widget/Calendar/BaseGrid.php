<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Generator;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Style;
use ipl\Web\Widget\Link;
use LogicException;
use SplObjectStorage;
use Traversable;

use function ipl\Stdlib\iterable_value_first;

/**
 * @phpstan-type GridArea array{0: int, 1: int, 2: int, 3: int}
 * @phpstan-type GridContinuationType self::FROM_PREV_GRID | self::TO_NEXT_GRID | self::ACROSS_GRID
 * @phpstan-type EdgeContinuationType self::ACROSS_LEFT_EDGE | self::ACROSS_RIGHT_EDGE | self::ACROSS_BOTH_EDGES
 * @phpstan-type ContinuationType GridContinuationType | EdgeContinuationType
 */
abstract class BaseGrid extends BaseHtmlElement
{
    use Translation;

    /** @var string The chronological order of entries is oriented horizontally */
    protected const HORIZONTAL_FLOW_OF_TIME = 'horizontal-flow';

    /** @var string The chronological order of entries is oriented vertically */
    protected const VERTICAL_FLOW_OF_TIME = 'vertical-flow';

    /** @var string Continuation of an entry that started on the previous grid */
    protected const FROM_PREV_GRID = 'from-prev-grid';

    /** @var string Continuation of an entry that continues on the next grid */
    protected const TO_NEXT_GRID = 'to-next-grid';

    /** @var string Continuation of an entry that started on the previous grid and continues on the next */
    protected const ACROSS_GRID = 'across-grid';

    /** @var string Continuation of an entry that started on a previous grid row */
    protected const ACROSS_LEFT_EDGE = 'across-left-edge';

    /** @var string Continuation of an entry that continues on the next grid row */
    protected const ACROSS_RIGHT_EDGE = 'across-right-edge';

    /** @var string Continuation of an entry that started on a previous grid row and continues on the next */
    protected const ACROSS_BOTH_EDGES = 'across-both-edges';

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

    /** @var array<string, array{0: int, 1: int}> */
    protected $entryColors = [];

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
     * @return Generator<array{0: GridArea, 1: ?ContinuationType}, Entry>
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
            $actualStart = $this->roundToNearestThirtyMinute($entry->getStart());
            if ($actualStart < $gridStartsAt) {
                $entryStartPos = 0;
            } else {
                $entryStartPos = Util::diffHours($gridStartsAt, $actualStart) * 2;
            }

            $actualEnd = $this->roundToNearestThirtyMinute($entry->getEnd());
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
                    $continuationType = self::ACROSS_BOTH_EDGES;
                } elseif ($backward) {
                    $continuationType = self::ACROSS_LEFT_EDGE;
                } elseif ($forward) {
                    $continuationType = self::ACROSS_RIGHT_EDGE;
                } elseif ($fromPrevGrid && $toNextGrid) {
                    $continuationType = self::ACROSS_GRID;
                } elseif ($fromPrevGrid) {
                    $continuationType = self::FROM_PREV_GRID;
                } elseif ($toNextGrid) {
                    $continuationType = self::TO_NEXT_GRID;
                }

                yield [$gridArea, $continuationType] => $entry;

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
     * @return Generator<array{0: GridArea, 1: ?ContinuationType}, Entry>
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

            $actualStart = $this->roundToNearestThirtyMinute($entry->getStart());
            if ($actualStart < $gridStartsAt) {
                $colStart = 0;
            } else {
                $colStart = Util::diffHours($gridStartsAt, $actualStart) * 2;
            }

            $actualEnd = $this->roundToNearestThirtyMinute($entry->getEnd());
            if ($actualEnd > $gridEndsAt) {
                $colEnd = $gridBorderAt;
            } else {
                $colEnd = Util::diffHours($gridStartsAt, $actualEnd) * 2;
            }

            if ($colStart > $gridBorderAt) {
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
                $continuationType = self::ACROSS_GRID;
            } elseif ($fromPrevGrid) {
                $continuationType = self::FROM_PREV_GRID;
            } elseif ($toNextGrid) {
                $continuationType = self::TO_NEXT_GRID;
            } else {
                $continuationType = null;
            }

            yield [$gridArea, $continuationType] => $entry;
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

        foreach ($generator as $data => $entry) {
            [$gridArea, $continuationType] = $data;

            $gradientClass = null;
            if ($continuationType === self::ACROSS_GRID || $continuationType === self::ACROSS_BOTH_EDGES) {
                $gradientClass = 'two-way-gradient';
            } elseif ($continuationType === self::FROM_PREV_GRID || $continuationType === self::ACROSS_LEFT_EDGE) {
                $gradientClass = 'opening-gradient';
            } elseif ($continuationType === self::TO_NEXT_GRID || $continuationType === self::ACROSS_RIGHT_EDGE) {
                $gradientClass = 'ending-gradient';
            }

            $entryHtml = new HtmlElement(
                'div',
                Attributes::create([
                    'class' => ['entry', $gradientClass, 'area-' . implode('-', $gridArea)],
                    'data-entry-id' => $entry->getId(),
                    'data-row-start' => $gridArea[0],
                    'data-col-start' => $gridArea[1],
                    'data-row-end' => $gridArea[2],
                    'data-col-end' => $gridArea[3]
                ])
            );

            $this->style->addFor($entryHtml, [
                '--entry-bg' => $this->getEntryColor($entry, 10),
                'grid-area' => sprintf('~"%d / %d / %d / %d"', ...$gridArea),
                'border-color' => $this->getEntryColor($entry, 50)
            ]);

            $this->assembleEntry($entryHtml, $entry, $continuationType);

            $overlay->addHtml($entryHtml);
        }
    }

    /**
     * Assemble the entry in the grid
     *
     * @param BaseHtmlElement $html Container where to add the entry's HTML
     * @param Entry $entry The entry to assemble
     * @param ?ContinuationType $continuationType Continuation type of the entry's HTML
     *
     * @return void
     */
    protected function assembleEntry(BaseHtmlElement $html, Entry $entry, ?string $continuationType): void
    {
        if (($url = $entry->getUrl()) !== null) {
            $entryContainer = new Link(null, $url);
            $entryContainer->openInModal();
            $html->addHtml($entryContainer);
        } else {
            $entryContainer = $html;
        }

        $title = new HtmlElement('div', Attributes::create(['class' => 'title']));
        $content = new HtmlElement(
            'div',
            Attributes::create(['class' => 'content'])
        );

        $titleAttr = $entry->getStart()->format('H:i')
            . ' | ' . $entry->getAttendee()->getName()
            . ': ' . $entry->getDescription();

        $startText = null;
        $endText = null;

        if ($continuationType === self::ACROSS_GRID) {
            $startText = sprintf($this->translate('starts %s'), $entry->getStart()->format('d/m/y'));
            $endText = sprintf($this->translate('ends %s'), $entry->getEnd()->format('d/m/y H:i'));
        } elseif ($continuationType === self::FROM_PREV_GRID) {
            $startText = sprintf($this->translate('starts %s'), $entry->getStart()->format('d/m/y'));
        } elseif ($continuationType === self::TO_NEXT_GRID) {
            $endText = sprintf($this->translate('ends %s'), $entry->getEnd()->format('d/m/y H:i'));
        }

        if ($startText) {
            $titleAttr = $startText . ' ' . $titleAttr;
        }

        if ($endText) {
            $titleAttr = $titleAttr . ' | ' . $endText;
        }

        $content->addAttributes(['title' => $titleAttr]);

        if ($continuationType !== null) {
            $title->addHtml(new HtmlElement(
                'time',
                Attributes::create([
                    'datetime' => $entry->getStart()->format(DateTimeInterface::ATOM)
                ]),
                Text::create($entry->getStart()->format($startText ? 'd/m/y H:i' : 'H:i'))
            ));
        }

        $title->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'attendee']),
                $entry->getAttendee()->getIcon(),
                Text::create($entry->getAttendee()->getName())
            )
        );

        $content->addHtml(
            $title,
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'description']),
                new HtmlElement(
                    'p',
                    Attributes::create(['title' => $entry->getDescription()]),
                    Text::create($entry->getDescription())
                )
            )
        );

        if ($endText) {
            $content->addHtml(
                HtmlElement::create(
                    'div',
                    ['class' => 'ends-at'],
                    $endText
                )
            );
        }

        $entryContainer->addHtml($content);
    }

    protected function roundToNearestThirtyMinute(DateTime $time): DateTime
    {
        $hour = (int) $time->format('H');
        $minute = (int) $time->format('i');

        $time = clone $time;
        if ($minute < 15) {
            $time->setTime($hour, 0);
        } elseif ($minute >= 45) {
            $time->setTime($hour + 1, 0);
        } else {
            $time->setTime($hour, 30);
        }

        return $time;
    }

    /**
     * Get the given attendee's color with the given transparency suitable for CSS
     *
     * @param Entry $entry
     * @param int<0, 100> $transparency
     *
     * @return string
     */
    protected function getEntryColor(Entry $entry, int $transparency): string
    {
        $attendeeName = $entry->getAttendee()->getName();
        if (! isset($this->entryColors[$attendeeName])) {
            // Get a representation of the attendee's name suitable for conversion to a decimal
            // TODO: There are how million colors in sRGB? Then why not use this as max value and ensure a good spread?
            //       Hashes always have a high number, so the reason why we use the remainder of the modulo operation
            //       below makes somehow sense, though it limits the variation to 360 colors which is not good enough.
            //       The saturation makes it more diverse, but only by a factor of 3. So essentially there are 360 * 3
            //       colors. By far lower than the 16.7 million colors in sRGB. But of course, we need distinct colors
            //       so if 500 thousand colors of these 16.7 millions are so similar that we can't distinguish them,
            //       there's no need for such a high variance. Hence we'd still need to partition the colors in a way
            //       that they are distinct enough.
            $hash = hexdec(hash('sha256', $attendeeName));
            // Limit the hue to a maximum of 360 as it's HSL's maximum of 360 degrees
            $h = (int) fmod($hash, 359.0); // TODO: Check if 359 is really of advantage here, instead of 360
            // The hue is already at least 1 degree off to every other, using a limited set of saturation values
            // further ensures that colors are distinct enough even if similar
            $s = [35, 50, 65][$h % 3];

            $this->entryColors[$attendeeName] = [$h, $s];
        } else {
            [$h, $s] = $this->entryColors[$attendeeName];
        }

        // We use a fixed luminosity to ensure good and equal contrast in both dark and light mode
        return sprintf('~"hsl(%d %d%% 50%% / %d%%)"', $h, $s, $transparency);
    }
}
