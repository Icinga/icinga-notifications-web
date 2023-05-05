<?php

namespace Icinga\Module\Noma\Widget;

use DateTime;
use Icinga\Module\Noma\Widget\Calendar\BaseGrid;
use Icinga\Module\Noma\Widget\Calendar\Controls;
use Icinga\Module\Noma\Widget\Calendar\Event;
use Icinga\Module\Noma\Widget\Calendar\MonthGrid;
use Icinga\Module\Noma\Widget\Calendar\Util;
use Icinga\Module\Noma\Widget\Calendar\WeekGrid;
use ipl\Html\BaseHtmlElement;
use ipl\Scheduler\RRule;
use ipl\Web\Url;
use LogicException;
use Traversable;

class Calendar extends BaseHtmlElement
{
    /** @var string Mode to show an entire month */
    public const MODE_MONTH = 'month';

    /** @var string Mode to show a specific calendar week */
    public const MODE_WEEK = 'week';

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'calendar'];

    /** @var Controls */
    protected $controls;

    /** @var BaseGrid The grid implementation */
    protected $grid;

    /** @var Event[] */
    protected $events = [];

    /** @var Url */
    protected $addEventUrl;

    public function setControls(Controls $controls): self
    {
        $this->controls = $controls;

        return $this;
    }

    public function getControls(): Controls
    {
        if ($this->controls === null) {
            throw new LogicException('No calendar controls set');
        }

        return $this->controls;
    }

    public function setAddEventUrl(?Url $url): self
    {
        $this->addEventUrl = $url;

        return $this;
    }

    public function getAddEventUrl(): ?Url
    {
        return $this->addEventUrl;
    }

    public function getGrid(): BaseGrid
    {
        if ($this->grid === null) {
            $mode = $this->getControls()->getValue('mode');
            if ($mode === self::MODE_MONTH) {
                $month = $this->getControls()->getValue('month') ?: (new DateTime())->format('Y-m');
                $this->grid = new MonthGrid($this, DateTime::createFromFormat(
                    'Y-m-d\TH:i:s',
                    $month . '-01T00:00:00'
                ));
            } else { // $mode === self::MODE_WEEK
                $week = $this->getControls()->getValue('week') ?: (new DateTime())->format('Y-\WW');
                $this->grid = new WeekGrid(
                    $this,
                    (new DateTime())
                        ->setTimestamp(strtotime($week))
                );
            }
        }

        return $this->grid;
    }

    public function addEvent(Event $event): self
    {
        $this->events[] = $event;

        return $this;
    }

    public function getEvents(): Traversable
    {
        foreach ($this->events as $event) {
            $rrule = $event->getRecurrencyRule();
            $start = $event->getStart();
            $end = $event->getEnd();

            if ($rrule) {
                $grid = $this->getGrid();
                $rrule = new RRule($rrule);
                $rrule->startAt($event->getStart());
                $length = $start->diff($end);

                $visibleHours = Util::diffHours($start, $grid->getGridEnd());
                $limit = (int) floor($visibleHours / (Util::diffHours($start, $end) ?: 0.5));
                if ($limit > $visibleHours) {
                    $limit = $visibleHours;
                }

                foreach ($rrule->getNextRecurrences($grid->getGridStart(), $limit) as $recurrence) {
                    $recurrenceEnd = (clone $recurrence)->add($length);
                    $occurrence = (new Event($event->getId()))
                        ->setDescription($event->getDescription())
                        ->setStart($recurrence)
                        ->setEnd($recurrenceEnd)
                        ->setIsOccurrence()
                        ->setUrl($event->getUrl())
                        ->setAttendee($event->getAttendee());

                    yield $occurrence;
                }
            } else {
                yield $event;
            }
        }
    }

    protected function assemble()
    {
        $this->addHtml(
            $this->getControls(),
            $this->getGrid()
        );
    }
}
