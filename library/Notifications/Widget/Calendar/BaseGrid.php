<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Icinga\Module\Notifications\Widget\Calendar;
use Icinga\Util\Csp;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Style;
use ipl\Web\Widget\Link;
use SplObjectStorage;
use Traversable;

/**
 * @phpstan-type ContinuationType self::ACROSS_GRID | self::FROM_PREV_GRID | self::TO_NEXT_GRID | self::ACROSS_EDGES
 */
abstract class BaseGrid extends BaseHtmlElement
{
    use Translation;

    /** @var string Continuation type of the entry row continuing from the previous grid */
    public const FROM_PREV_GRID = 'from-prev-grid';

    /** @var string Continuation type of the entry row continuing to the next grid */
    public const TO_NEXT_GRID = 'to-next-grid';

    /** @var string Continuation type of the entry row continuing from the previous grid to the next grid */
    public const ACROSS_GRID = 'across-grid';

    /** @var string Continuation type of the entry row continuing across edges of the grid */
    public const ACROSS_EDGES = 'across-edges';

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'calendar-grid'];

    /** @var Calendar */
    protected $calendar;

    /** @var DateTime */
    protected $start;

    /** @var DateTime */
    protected $end;

    /** @var array Extra counts stored as [date1 => count1, date2 => count2]*/
    protected $extraEntriesCount = [];

    /**
     * Create a new calendar
     *
     * @param DateTime $start When the shown timespan should start
     */
    public function __construct(Calendar $calendar, DateTime $start)
    {
        $this->calendar = $calendar;
        $this->setGridStart($start);
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

    abstract protected function createGridSteps(): Traversable;

    abstract protected function calculateGridEnd(): DateTime;

    abstract protected function getNoOfVisuallyConnectedHours(): int;

    abstract protected function getGridArea(int $rowStart, int $rowEnd, int $colStart, int $colEnd): array;

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
        $url = $this->calendar->getAddEntryUrl();
        foreach ($this->createGridSteps() as $gridStep) {
            $step = new HtmlElement(
                'div',
                Attributes::create([
                    'class' => 'step',
                    'data-start' => $gridStep->format(DateTimeInterface::ATOM)
                ])
            );

            if ($url !== null) {
                $content = new Link(null, $url->with('start', $gridStep->format('Y-m-d\TH:i:s')));
                $step->addHtml($content);
            } else {
                $content = $step;
            }

            $this->assembleGridStep($content, $gridStep);

            $grid->addHtml($step);
        }
    }

    protected function assembleGridStep(BaseHtmlElement $content, DateTime $step): void
    {
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

    protected function assembleGridOverlay(BaseHtmlElement $overlay): void
    {
        $style = (new Style())->setNonce(Csp::getStyleNonce());
        $style->setModule('notifications'); // TODO: Don't hardcode this!
        $style->setSelector('.calendar-grid .overlay');

        $overlay->addHtml($style);

        $sectionsPerStep = $this->getSectionsPerStep();

        $gridStartsAt = $this->getGridStart();
        $gridEndsAt = $this->getGridEnd();
        $amountOfDays = $gridStartsAt->diff($gridEndsAt)->days;
        $gridBorderAt = $this->getNoOfVisuallyConnectedHours() * 2;

        $cellOccupiers = [];
        /** @var SplObjectStorage<Entry, int[][]> $occupiedCells */
        $occupiedCells = new SplObjectStorage();
        foreach ($this->calendar->getEntries() as $entry) {
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

                    $rowStart = $row + $this->getRowStartModifier();
                    $rowSpan = $this->getMaximumRowSpan();

                    $competingOccupiers = array_filter($occupiers, function ($id) use ($rowPlacements, $row) {
                        return isset($rowPlacements[$id][$row]);
                    });
                    usort($competingOccupiers, function ($id, $otherId) use ($rowPlacements, $row) {
                        return $rowPlacements[$id][$row][0] <=> $rowPlacements[$otherId][$row][0];
                    });

                    foreach ($competingOccupiers as $otherId) {
                        list($otherRowStart, $otherRowSpan) = $rowPlacements[$otherId][$row];
                        if ($otherRowStart === $rowStart) {
                            $otherRowSpan = (int) ceil($otherRowSpan / 2);
                            $rowStart += $otherRowSpan;
                            $rowSpan -= $otherRowSpan;
                            $rowPlacements[$otherId][$row] = [$otherRowStart, $otherRowSpan];
                        } else {
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
                if ($rowStart > $row + $sectionsPerStep) {
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

                $entryClass = 'area-' . implode('-', $gridArea);
                $lastRow = $remainingRows === 1;

                if ($lastRow) {
                    $toNextGrid = $gridEndsAt < $entry->getEnd();
                }

                $backward = $continuationType || $fromPrevGrid;
                $forward = ! $lastRow || $toNextGrid;
                $gradientClass = null;
                if ($forward && $backward) {
                    $gradientClass = 'two-way-gradient';
                } elseif ($backward) {
                    $gradientClass = 'opening-gradient';
                } elseif ($forward) {
                    $gradientClass = 'ending-gradient';
                }

                $style->add(".$entryClass", [
                    '--entry-bg' => $entry->getAttendee()->getColor() . dechex((int) (256 * 0.1)),
                    'grid-area' => sprintf('~"%d / %d / %d / %d"', ...$gridArea),
                    'border-color' => $entry->getAttendee()->getColor() . dechex((int) (256 * 0.5))
                ]);

                $entryHtml = new HtmlElement(
                    'div',
                    Attributes::create([
                        'class' => ['entry', $gradientClass, $entryClass],
                        'data-entry-id' => $entry->getId(),
                        'data-row-start' => $gridArea[0],
                        'data-col-start' => $gridArea[1],
                        'data-row-end' => $gridArea[2],
                        'data-col-end' => $gridArea[3]
                    ])
                );

                if ($fromPrevGrid) {
                    $continuationType = $toNextGrid ? self::ACROSS_GRID : self::FROM_PREV_GRID;
                } elseif ($toNextGrid) {
                    $continuationType = self::TO_NEXT_GRID;
                } elseif ($forward) {
                    $continuationType = self::ACROSS_EDGES;
                }

                $this->assembleEntry($entryHtml, $entry, $continuationType);
                $overlay->addHtml($entryHtml);

                $fromPrevGrid = false;
                $remainingRows -= 1;
            }
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
            $title->addHtml(
                HtmlElement::create(
                    'div',
                    ['class' => 'starts-at'],
                    $startText
                )
            );
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
                Text::create($entry->getStart()->format('H:i'))
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
}
