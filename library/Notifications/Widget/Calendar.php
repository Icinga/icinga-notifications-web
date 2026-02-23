<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use DateTime;
use Icinga\Module\Notifications\Widget\Calendar\Controls;
use Icinga\Module\Notifications\Widget\Calendar\DayGrid;
use Icinga\Module\Notifications\Widget\Calendar\Entry;
use Icinga\Module\Notifications\Widget\Calendar\MonthGrid;
use Icinga\Module\Notifications\Widget\Calendar\WeekGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\BaseGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\EntryProvider;
use Icinga\Module\Notifications\Widget\TimeGrid\GridStep;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\StaticTranslator;
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
                $this->getAttributes()->get('class')->addValue('month');
            } elseif ($this->getControls()->getViewMode() === self::MODE_WEEK) {
                $this->grid = new WeekGrid($this, $this->getStyle(), $this->getModeStart());
                $this->getAttributes()->get('class')->addValue('week');
            } else {
                $this->grid = new DayGrid($this, $this->getStyle(), $this->getModeStart());
                $this->getAttributes()->get('class')->addValue('day');
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
        yield from $this->entries;
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
