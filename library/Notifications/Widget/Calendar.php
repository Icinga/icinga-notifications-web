<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use DateTime;
use Icinga\Module\Notifications\Widget\Calendar\BaseGrid;
use Icinga\Module\Notifications\Widget\Calendar\Controls;
use Icinga\Module\Notifications\Widget\Calendar\DayGrid;
use Icinga\Module\Notifications\Widget\Calendar\Entry;
use Icinga\Module\Notifications\Widget\Calendar\EntryProvider;
use Icinga\Module\Notifications\Widget\Calendar\GridStep;
use Icinga\Module\Notifications\Widget\Calendar\MonthGrid;
use Icinga\Module\Notifications\Widget\Calendar\Util;
use Icinga\Module\Notifications\Widget\Calendar\WeekGrid;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\StaticTranslator;
use ipl\Scheduler\RRule;
use ipl\Web\Style;
use ipl\Web\Url;
use LogicException;
use Traversable;

class Calendar extends BaseHtmlElement implements EntryProvider
{
    /** @var string Mode to show an entire month */
    public const MODE_MONTH = 'month';

    /** @var string Mode to show a specific calendar week */
    public const MODE_WEEK = 'week';

    /** @var string Mode to show only the day */
    public const MODE_DAY = 'day';

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'calendar'];

    /** @var Controls */
    protected $controls;

    /** @var Style */
    protected $style;

    /** @var BaseGrid The grid implementation */
    protected $grid;

    /** @var Entry[] */
    protected $entries = [];

    /** @var Url */
    protected $addEntryUrl;

    /** @var ?Url */
    protected $url;

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

    public function setStyle(Style $style): self
    {
        $this->style = $style;

        return $this;
    }

    public function getStyle(): Style
    {
        if ($this->style === null) {
            $this->style = new Style();
        }

        return $this->style;
    }

    public function setAddEntryUrl(?Url $url): self
    {
        $this->addEntryUrl = $url;

        return $this;
    }

    public function getStepUrl(GridStep $step): ?Url
    {
        if ($this->addEntryUrl === null) {
            return null;
        }

        return $this->addEntryUrl->with('start', $step->getStart()->format('Y-m-d\TH:i:s'));
    }

    public function setUrl(?Url $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getExtraEntryUrl(GridStep $step): ?Url
    {
        return $this->url
            ? (clone $this->url)->overwriteParams([
                'mode' => 'day',
                'day'  => $step->getStart()->format('Y-m-d')
            ])
            : null;
    }

    protected function getModeStart(): DateTime
    {
        switch ($this->getControls()->getViewMode()) {
            case self::MODE_MONTH:
                $month = $this->getControls()->getValue('month') ?: (new DateTime())->format(Controls::MONTH_FORMAT);

                return DateTime::createFromFormat('Y-m-d\TH:i:s', $month . '-01T00:00:00');
            case self::MODE_WEEK:
                $week = $this->getControls()->getValue('week') ?: (new DateTime())->format(Controls::WEEK_FORMAT);

                return (new DateTime())->setTimestamp(strtotime($week));
            default:
                $day = $this->getControls()->getValue('day') ?: (new DateTime())->format(Controls::DAY_FORMAT);

                return DateTime::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00');
        }
    }

    public function getGrid(): BaseGrid
    {
        if ($this->grid === null) {
            if ($this->getControls()->getViewMode() === self::MODE_MONTH) {
                $this->grid = new MonthGrid($this, $this->getStyle(), $this->getModeStart());
            } elseif ($this->getControls()->getViewMode() === self::MODE_WEEK) {
                $this->grid = new WeekGrid($this, $this->getStyle(), $this->getModeStart());
            } else {
                $this->grid = new DayGrid($this, $this->getStyle(), $this->getModeStart());
            }
        }

        return $this->grid;
    }

    public function addEntry(Entry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    public function getEntries(): Traversable
    {
        foreach ($this->entries as $entry) {
            $rrule = $entry->getRecurrencyRule();
            $start = $entry->getStart();
            $end = $entry->getEnd();

            if ($rrule) {
                $grid = $this->getGrid();
                $rrule = new RRule($rrule);
                $rrule->startAt($entry->getStart());
                $length = $start->diff($end);

                $visibleHours = Util::diffHours($start, $grid->getGridEnd());
                $limit = (int) ceil($visibleHours / (Util::diffHours($start, $end) ?: 0.5));
                if ($limit > $visibleHours) {
                    $limit = $visibleHours;
                }

                $recurrenceStart = (clone $grid->getGridStart())->sub($length);
                foreach ($rrule->getNextRecurrences($recurrenceStart, $limit) as $recurrence) {
                    $recurrenceEnd = (clone $recurrence)->add($length);
                    $occurrence = (new Entry($entry->getId()))
                        ->setDescription($entry->getDescription())
                        ->setStart($recurrence)
                        ->setEnd($recurrenceEnd)
                        ->setIsOccurrence()
                        ->setUrl($entry->getUrl())
                        ->setAttendee($entry->getAttendee());

                    yield $occurrence;
                }
            } else {
                yield $entry;
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
            $this->getGrid(),
            $this->getStyle()
        );
    }
}
