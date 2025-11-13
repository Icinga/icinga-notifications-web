<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Widget\Calendar;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Widget\Icon;
use Locale;

class Controls extends Form
{
    use BaseTarget;
    use Translation;

    /** @var string The datetime format used for days */
    public const DAY_FORMAT = 'Y-m-d';

    /** @var string The datetime format used for weeks */
    public const WEEK_FORMAT = 'o-\WW';

    /** @var string The datetime format used for months */
    public const MONTH_FORMAT = 'Y-m';

    protected $method = 'GET';

    protected $defaultAttributes = ['class' => 'calendar-controls'];

    public function getViewMode(): string
    {
        return $this->getPopulatedValue('mode', Calendar::MODE_WEEK);
    }

    protected function assemble(): void
    {
        switch ($this->getPopulatedValue('mode', Calendar::MODE_WEEK)) {
            case Calendar::MODE_MONTH:
                $this->assembleMonthSelectors();
                break;
            case Calendar::MODE_WEEK:
                $this->assembleWeekSelectors();
                break;
            default:
                $this->assembleDaySelectors();
                break;
        }

        $modeParam = 'mode';
        $options = [
            Calendar::MODE_DAY => $this->translate('Day'),
            Calendar::MODE_WEEK => $this->translate('Week'),
            Calendar::MODE_MONTH => $this->translate('Month')
        ];

        $modeSwitcher = HtmlElement::create('fieldset', ['class' => 'view-mode-switcher']);
        foreach ($options as $value => $label) {
            $input = $this->createElement('input', $modeParam, [
                'class' => 'autosubmit',
                'type'  => 'radio',
                'id' => $modeParam . '-' . $value,
                'value' => $value
            ]);

            $input->getAttributes()->registerAttributeCallback('checked', function () use ($value) {
                return $value === $this->getViewMode();
            });

            $modeSwitcher->addHtml(
                $input,
                new HtmlElement('label', Attributes::create(['for' => 'mode-' . $value]), Text::create($label))
            );
        }

        $this->addHtml($modeSwitcher);
    }

    protected function assembleDaySelectors(): void
    {
        /** @var ?string $chosenDay */
        $chosenDay = $this->getPopulatedValue('day');
        if ($chosenDay) {
            $chosenDay = DateTime::createFromFormat(self::DAY_FORMAT, $chosenDay);
            if (! $chosenDay) {
                $chosenDay = new DateTime();
            }
        } else {
            $chosenDay = new DateTime();
        }

        $previousDay = (clone $chosenDay)->sub(new DateInterval('P1D'));
        $previousBtn = $this->createElement('button', 'day', [
            'value' => $previousDay->format(self::DAY_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s'),
                $this->getLocalizedDay($previousDay)
            )
        ]);
        $this->addHtml($previousBtn->addHtml(new Icon('angle-left')));

        $dayInput = $this->createElement('input', 'day', [
            'class' => 'autosubmit',
            'type' => 'date',
            'value' => (new DateTime())->format(self::DAY_FORMAT),
            'title' => $this->translate('Show a different day')
        ]);
        $this->registerElement($dayInput);
        $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'icinga-controls']), $dayInput));

        $nextDay = (clone $chosenDay)->add(new DateInterval('P1D'));
        $nextBtn = $this->createElement('button', 'day', [
            'value' => $nextDay->format(self::DAY_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s'),
                $this->getLocalizedDay($nextDay)
            )
        ]);
        $this->addHtml($nextBtn->addHtml(new Icon('angle-right')));
    }

    protected function assembleWeekSelectors(): void
    {
        /** @var ?string $chosenWeek */
        $chosenWeek = $this->getPopulatedValue('week');
        if ($chosenWeek) {
            $chosenWeekTime = strtotime($chosenWeek);
            $chosenWeek = new DateTime();
            if ($chosenWeekTime) {
                $chosenWeek->setTimestamp($chosenWeekTime);
            }
        } else {
            $chosenWeek = new DateTime();
        }

        $chosenYear = (int) $chosenWeek->format('o');
        $chosenWeekFormatted = $chosenWeek->format(self::WEEK_FORMAT);

        $chosenWeek->setISODate($chosenYear, (int) $chosenWeek->format('W'));
        if ($chosenWeek->format('Y') < $chosenWeek->format('o')) {
            // In case the week's current day is not in the same year as the week (according to ISO 8601), we
            // can safely assume the month to be January, since we forced the week to start on it's monday and
            // can therefore derive that the deviation can only occur if the week belongs to the following year.
            $chosenMonth = 1;
        } else {
            $chosenMonth = (int) $chosenWeek->format('n');
        }

        $previousYear = (clone $chosenWeek)->sub(new DateInterval('P1Y'));
        $nextYear = (clone $chosenWeek)->add(new DateInterval('P1Y'));
        $previousMonth = (clone $chosenWeek)->sub(new DateInterval('P1M'));
        $nextMonth = (clone $chosenWeek)->add(new DateInterval('P1M'));

        $this->addElement('hidden', 'week', [
            'disabled' => true
        ]);

        // Let's be pragmatic and offer the user only a limited set of years.
        $years = [];
        $start = (clone $chosenWeek)->sub(new DateInterval('P5Y'));
        for ($i = 0; $i <= 10; $i++) {
            $year = (int) $start->format('Y');

            if ($year === $chosenYear) {
                $week = $chosenWeek;
            } else {
                $week = $this->getFirstMonday($chosenMonth, $year);

                if ($year < $chosenYear && $chosenYear - $year === 1) {
                    $previousYear = $week;
                } elseif ($year > $chosenYear && $year - $chosenYear === 1) {
                    $nextYear = $week;
                }
            }

            $years[$week->format(self::WEEK_FORMAT)] = (string) $year;
            $start->add(new DateInterval('P1Y'));
        }

        // But ensure the current year is only a single click away
        $now = new DateTime();
        if ($start < $now) {
            $years[''] = '…';
            $years[$now->format(self::WEEK_FORMAT)] = $now->format('Y');
        } elseif (! isset($years[$now->format(self::WEEK_FORMAT)])) {
            $years = array_merge([
                $now->format(self::WEEK_FORMAT) => $now->format('Y'),
                '' => '…'
            ], $years);
        }

        $months = [];
        for ($i = 1; $i <= 12; $i++) {
            if ($chosenMonth === $i) {
                $month = $chosenWeek;
            } else {
                $month = $this->getFirstMonday($i, $chosenYear);

                if ($i < $chosenMonth && $chosenMonth - $i === 1) {
                    $previousMonth = $month;
                } elseif ($i > $chosenMonth && $i - $chosenMonth === 1) {
                    $nextMonth = $month;
                }
            }

            $months[$month->format(self::WEEK_FORMAT)] = $this->getLocalizedMonth($month);
        }

        $weeks = [];
        $firstWeekDayOfMonth = $this->getFirstMonday($chosenMonth, $chosenYear);
        do {
            $weeks[$firstWeekDayOfMonth->format(self::WEEK_FORMAT)] = sprintf(
                $this->translate('%s to %s'),
                ...$this->getLocalizedWeek($firstWeekDayOfMonth)
            );
            $firstWeekDayOfMonth->add(new DateInterval('P1W'));
        } while (
            (int) $firstWeekDayOfMonth->format('n') === $chosenMonth
            && (int) $firstWeekDayOfMonth->format('o') === $chosenYear
        );

        // The current week should also be only a single click away
        if ($firstWeekDayOfMonth < $now) {
            $weeks[''] = '…';
            $weeks[$now->format(self::WEEK_FORMAT)] = sprintf(
                $this->translate('%s to %s'),
                ...$this->getLocalizedWeek($now)
            );
        } elseif (! isset($weeks[$now->format(self::WEEK_FORMAT)])) {
            $weeks = array_merge([
                $now->format(self::WEEK_FORMAT) => sprintf(
                    $this->translate('%s to %s'),
                    ...$this->getLocalizedWeek($now)
                ),
                '' => '…'
            ], $weeks);
        }

        $previousYearBtn = $this->createElement('button', 'week', [
            'value' => $previousYear->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($previousYear)
            )
        ])->addHtml(new Icon('angle-left'));
        $this->addHtml($previousYearBtn);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'icinga-controls']),
            $this->createElement('select', 'week', [
                'class' => 'autosubmit',
                'value' => $chosenWeekFormatted,
                'options' => $years,
                'disabledOptions' => [$chosenWeekFormatted, ''],
                'title' => sprintf(
                    $this->translate('Show the first week in %s of a different year'),
                    $this->getLocalizedMonth($chosenWeek)
                )
            ])
        ));

        $nextYearBtn = $this->createElement('button', 'week', [
            'value' => $nextYear->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($nextYear)
            )
        ])->addHtml(new Icon('angle-right'));
        $this->addHtml($nextYearBtn);

        $previousMonthBtn = $this->createElement('button', 'week', [
            'value' => $previousMonth->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($previousMonth)
            )
        ])->addHtml(new Icon('angle-left'));
        $this->addHtml($previousMonthBtn);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'icinga-controls']),
            $this->createElement('select', 'week', [
                'class' => 'autosubmit',
                'value' => $chosenWeekFormatted,
                'options' => $months,
                'disabledOptions' => [$chosenWeekFormatted],
                'title' => sprintf(
                    $this->translate('Show a different month in %d'),
                    $chosenYear
                )
            ])
        ));

        $nextMonthBtn = $this->createElement('button', 'week', [
            'value' => $nextMonth->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($nextMonth)
            )
        ])->addHtml(new Icon('angle-right'));
        $this->addHtml($nextMonthBtn);

        $previousWeek = (clone $chosenWeek)->sub(new DateInterval('P1W'));
        $previousWeekBtn = $this->createElement('button', 'week', [
            'value' => $previousWeek->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($previousWeek)
            )
        ])->addHtml(new Icon('angle-left'));
        $this->addHtml($previousWeekBtn);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'icinga-controls']),
            $this->createElement('select', 'week', [
                'class' => 'autosubmit',
                'value' => $chosenWeekFormatted,
                'options' => $weeks,
                'disabledOptions' => [$chosenWeekFormatted, ''],
                'title' => sprintf(
                    $this->translate('Show a different week in %s of %d'),
                    $this->getLocalizedMonth($chosenWeek),
                    $chosenYear
                )
            ])
        ));

        $nextWeek = (clone $chosenWeek)->add(new DateInterval('P1W'));
        $nextWeekBtn = $this->createElement('button', 'week', [
            'value' => $nextWeek->format(self::WEEK_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s to %s'),
                ...$this->getLocalizedWeek($nextWeek)
            )
        ])->addHtml(new Icon('angle-right'));
        $this->addHtml($nextWeekBtn);
    }

    protected function assembleMonthSelectors(): void
    {
        /** @var ?string $chosenMonth */
        $chosenMonth = $this->getPopulatedValue('month');
        if ($chosenMonth) {
            $chosenMonth = DateTime::createFromFormat(self::MONTH_FORMAT, $chosenMonth);
            if (! $chosenMonth) {
                $chosenMonth = new DateTime();
            }
        } else {
            $chosenMonth = new DateTime();
        }

        $chosenMonthFormatted = $chosenMonth->format(self::MONTH_FORMAT);

        $this->addElement('hidden', 'month', [
            'disabled' => true
        ]);

        $previousYear = (clone $chosenMonth)->sub(new DateInterval('P1Y'));
        $previousYearBtn = $this->createElement('button', 'month', [
            'value' => $previousYear->format(self::MONTH_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s %d'),
                $this->getLocalizedMonth($previousYear),
                $previousYear->format('Y')
            )
        ])->addHtml(new Icon('angle-left'));
        $this->addHtml($previousYearBtn);

        // Let's be pragmatic and offer the user only a limited set of years.
        $years = [];
        $year = (clone $chosenMonth)->sub(new DateInterval('P5Y'));
        for ($i = 0; $i <= 10; $i++) {
            $year->setDate(
                (int) $year->format('Y'),
                (int) $chosenMonth->format('n'),
                1
            );

            $years[$year->format(self::MONTH_FORMAT)] = $year->format('Y');
            $year->add(new DateInterval('P1Y'));
        }

        // But ensure the current year is only a single click away
        $now = new DateTime();
        if ($year < $now) {
            $years[''] = '…';
            $years[$now->format(self::MONTH_FORMAT)] = $now->format('Y');
        } elseif (! isset($years[$now->format(self::MONTH_FORMAT)])) {
            $years = array_merge([
                $now->format(self::MONTH_FORMAT) => $now->format('Y'),
                '' => '…'
            ], $years);
        }

        $months = [];
        $month = (new DateTime())->setDate(
            (int) $chosenMonth->format('Y'),
            1,
            1
        );
        for ($i = 1; $i <= 12; $i++) {
            $months[$month->format(self::MONTH_FORMAT)] = $this->getLocalizedMonth($month);
            $month->add(new DateInterval('P1M'));
        }

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'icinga-controls']),
            $this->createElement('select', 'month', [
                'class' => 'autosubmit',
                'value' => $chosenMonthFormatted,
                'options' => $years,
                'disabledOptions' => [$chosenMonthFormatted, ''],
                'title' => sprintf(
                    $this->translate('Show %s of a different year'),
                    $this->getLocalizedMonth($chosenMonth)
                )
            ])
        ));

        $nextYear = (clone $chosenMonth)->add(new DateInterval('P1Y'));
        $nextYearBtn = $this->createElement('button', 'month', [
            'value' => $nextYear->format(self::MONTH_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s %d'),
                $this->getLocalizedMonth($nextYear),
                $nextYear->format('Y')
            )
        ])->addHtml(new Icon('angle-right'));
        $this->addHtml($nextYearBtn);

        $previousMonth = (clone $chosenMonth)->sub(new DateInterval('P1M'));
        $previousMonthBtn = $this->createElement('button', 'month', [
            'value' => $previousMonth->format(self::MONTH_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s %d'),
                $this->getLocalizedMonth($previousMonth),
                $previousMonth->format('Y')
            )
        ])->addHtml(new Icon('angle-left'));
        $this->addHtml($previousMonthBtn);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'icinga-controls']),
            $this->createElement('select', 'month', [
                'class' => 'autosubmit',
                'value' => $chosenMonthFormatted,
                'options' => $months,
                'disabledOptions' => [$chosenMonthFormatted],
                'title' => sprintf(
                    $this->translate('Show a different month in %d'),
                    $chosenMonth->format('Y')
                )
            ])
        ));

        $nextMonth = $chosenMonth->add(new DateInterval('P1M'));
        $nextMonthBtn = $this->createElement('button', 'month', [
            'value' => $nextMonth->format(self::MONTH_FORMAT),
            'class' => 'control-button',
            'type' => 'submit',
            'title' => sprintf(
                $this->translate('Show %s %d'),
                $this->getLocalizedMonth($nextMonth),
                $nextMonth->format('Y')
            )
        ])->addHtml(new Icon('angle-right'));
        $this->addHtml($nextMonthBtn);
    }

    /**
     * Get the first monday of the given month
     *
     * @param int $month
     * @param int $year
     *
     * @return DateTime
     */
    private function getFirstMonday(int $month, int $year): DateTime
    {
        /** @var DateTime $firstDay */
        $firstDay = DateTime::createFromFormat('Y-m-d', sprintf('%d-%d-1', $year, $month));

        $theDayBefore = (clone $firstDay)->sub(new DateInterval('P1D'));
        if ($theDayBefore->format('Y') < $theDayBefore->format('o')) {
            while ($firstDay->format('N') !== '1') {
                $firstDay->sub(new DateInterval('P1D'));
            }
        } else {
            while ($firstDay->format('N') !== '1') {
                $firstDay->add(new DateInterval('P1D'));
            }
        }

        return $firstDay;
    }

    /**
     * Get a localized text representation of the given day
     *
     * @param DateTime $day
     *
     * @return string
     */
    private function getLocalizedDay(DateTime $day): string
    {
        return (string) (new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE
        ))->format($day);
    }

    /**
     * Get a localized text representation of monday and sunday in the given week
     *
     * @param DateTime $dateTime
     *
     * @return string[]
     */
    private function getLocalizedWeek(DateTime $dateTime): array
    {
        $year = (int) $dateTime->format('o');
        $weekNo = (int) $dateTime->format('W');

        $monday = $this->getLocalizedDay((new DateTime())->setISODate($year, $weekNo));
        $sunday = $this->getLocalizedDay((new DateTime())->setISODate($year, $weekNo, 7));

        return [$monday, $sunday];
    }

    /**
     * Get a localized text representation of the given month
     *
     * @param DateTime $dateTime
     *
     * @return string
     */
    private function getLocalizedMonth(DateTime $dateTime): string
    {
        $dateTime = clone $dateTime;
        while ($dateTime->format('Y') < $dateTime->format('o')) {
            // According to ISO 8601, a year's first week may start in the previous year,
            // so we need to adjust the given datetime to render the correct month
            $dateTime->add(new DateInterval('P1D'));
        }

        return (string) (new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            date_default_timezone_get(),
            IntlDateFormatter::GREGORIAN,
            'MMMM'
        ))->format($dateTime);
    }
}
