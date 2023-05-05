<?php

namespace Icinga\Module\Noma\Widget\Calendar;

use DateTime;
use DateTimeInterface;
use Icinga\Module\Noma\Common\Style;
use Icinga\Module\Noma\Widget\Calendar;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Widget\Link;
use SplObjectStorage;
use Traversable;

abstract class BaseGrid extends BaseHtmlElement
{
    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'calendar-grid'];

    /** @var Calendar */
    protected $calendar;

    /** @var DateTime */
    protected $start;

    /** @var DateTime */
    protected $end;

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
        $url = $this->calendar->getAddEventUrl();
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

    protected function assembleGridOverlay(BaseHtmlElement $overlay): void
    {
        $style = new Style();
        $style->setModule('noma'); // TODO: Don't hardcode this!
        $style->setParentSelector('.calendar-grid .overlay');

        $overlay->addHtml($style);

        $sectionsPerStep = $this->getSectionsPerStep();

        $gridStartsAt = $this->getGridStart();
        $gridEndsAt = $this->getGridEnd();
        $amountOfDays = $gridStartsAt->diff($gridEndsAt)->days;
        $gridBorderAt = $this->getNoOfVisuallyConnectedHours() * 2;

        $cellOccupiers = [];
        $occupiedCells = new SplObjectStorage();
        foreach ($this->calendar->getEvents() as $event) {
            $actualStart = $this->roundToNearestThirtyMinute($event->getStart());
            if ($actualStart < $gridStartsAt) {
                $eventStartPos = 0;
            } else {
                $eventStartPos = Util::diffHours($gridStartsAt, $actualStart) * 2;
            }

            $actualEnd = $this->roundToNearestThirtyMinute($event->getEnd());
            if ($actualEnd > $gridEndsAt) {
                $eventEndPos = $amountOfDays * 48;
            } else {
                $eventEndPos = Util::diffHours($gridStartsAt, $actualEnd) * 2;
            }

            $rows = [];
            for ($i = $eventStartPos; $i < $eventEndPos && $i < $amountOfDays * 48; $i++) {
                $row = (int) floor($i / $gridBorderAt);
                $column = $i % $gridBorderAt;
                $rowStart = $row * $sectionsPerStep;
                $rows[$rowStart][] = $column;
                $cellOccupiers[$rowStart][$column][] = spl_object_id($event);
            }

            $occupiedCells->attach($event, $rows);
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

        foreach ($occupiedCells as $event) {
            $continuation = false;
            $rows = $occupiedCells->getInfo();
            foreach ($rows as $row => $hours) {
                list($rowStart, $rowSpan) = $rowPlacements[spl_object_id($event)][$row];
                if ($rowStart > $row + $sectionsPerStep) {
                    // TODO: Register as +1
                    continue;
                }

                $rowEnd = $rowStart + $rowSpan;
                $colStart = min($hours) + 1;
                $colEnd = max($hours) + 2;

                $gridArea = $this->getGridArea($rowStart, $rowEnd, $colStart, $colEnd);
                $entryClass = 'area-' . implode('-', $gridArea);

                $style->addRule(".$entryClass", [
                    'grid-area' => sprintf('~"%d / %d / %d / %d"', ...$gridArea),
                    'background-color' => $event->getAttendee()->getColor()
                ]);

                $entry = new HtmlElement(
                    'div',
                    Attributes::create([
                        'class' => ['entry', $entryClass],
                        'data-event-id' => $event->getId(),
                        'data-row-start' => $gridArea[0],
                        'data-col-start' => $gridArea[1],
                        'data-row-end' => $gridArea[2],
                        'data-col-end' => $gridArea[3]
                    ])
                );
                $this->assembleEntry($entry, $event, $continuation);
                $overlay->addHtml($entry);

                $continuation = true;
            }
        }
    }

    protected function assembleEntry(BaseHtmlElement $entry, Event $event, bool $isContinuation): void
    {
        if (($url = $event->getUrl()) !== null) {
            $entryContainer = new Link(null, $url);
            $entry->addHtml($entryContainer);
        } else {
            $entryContainer = $entry;
        }

        $title = new HtmlElement('p', Attributes::create(['class' => 'title']));
        if (! $isContinuation) {
            $title->addHtml(new HtmlElement(
                'time',
                Attributes::create([
                    'datetime' => $event->getStart()->format(DateTimeInterface::ATOM)
                ]),
                Text::create($event->getStart()->format('H:i'))
            ));
        }

        $title->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'attendee']),
                $event->getAttendee()->getIcon(),
                Text::create($event->getAttendee()->getName())
            )
        );

        $entryContainer->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'content']),
            $title,
            new HtmlElement('p', Attributes::create(['class' => 'description']), Text::create($event->getDescription()))
        ));
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
