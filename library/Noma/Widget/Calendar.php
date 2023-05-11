<?php

namespace Icinga\Module\Noma\Widget;

use DateTime;
use Icinga\Module\Noma\Widget\Calendar\BaseGrid;
use Icinga\Module\Noma\Widget\Calendar\Controls;
use Icinga\Module\Noma\Widget\Calendar\Event;
use Icinga\Module\Noma\Widget\Calendar\MonthGrid;
use Icinga\Module\Noma\Widget\Calendar\Util;
use Icinga\Module\Noma\Widget\Calendar\WeekGrid;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\StaticTranslator;
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

    protected function getModeStart(): DateTime
    {
        switch ($this->getControls()->getViewMode()) {
            case self::MODE_MONTH:
                $month = $this->getControls()->getValue('month') ?: (new DateTime())->format('Y-m');

                return DateTime::createFromFormat('Y-m-d\TH:i:s', $month . '-01T00:00:00');
            case self::MODE_WEEK:
            default:
                $week = $this->getControls()->getValue('week') ?: (new DateTime())->format('Y-\WW');

                return (new DateTime())->setTimestamp(strtotime($week));
        }
    }

    public function getGrid(): BaseGrid
    {
        if ($this->grid === null) {
            if ($this->getControls()->getViewMode() === self::MODE_MONTH) {
                $this->grid = new MonthGrid($this, $this->getModeStart());
            } else { // $mode === self::MODE_WEEK
                $this->grid = new WeekGrid($this, $this->getModeStart());
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

                $recurrenceStart = (clone $grid->getGridStart())->sub($length);
                foreach ($rrule->getNextRecurrences($recurrenceStart, $limit) as $recurrence) {
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
        $modeStart = $this->getModeStart();

        if (method_exists(StaticTranslator::$instance, 'getLocale')) {
            $month = (new IntlDateFormatter(
                StaticTranslator::$instance->getLocale(),
                IntlDateFormatter::FULL,
                IntlDateFormatter::FULL,
                date_default_timezone_get(),
                IntlDateFormatter::GREGORIAN,
                'MMMM'
            ))->format($modeStart);
        } else {
            $month = $modeStart->format('F');
        }

        $this->addHtml(
            $this->getControls(),
            new HtmlElement('h3', Attributes::create(['class' => 'calendar-title']), FormattedString::create(
                '%s %s',
                new HtmlElement('strong', null, Text::create($month)),
                $modeStart->format('Y')
            )),
            $this->getGrid()
        );
    }
}
