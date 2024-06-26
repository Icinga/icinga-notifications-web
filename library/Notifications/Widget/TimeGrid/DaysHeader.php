<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateInterval;
use DateTime;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use Locale;

class DaysHeader extends BaseHtmlElement
{
    use Translation;

    /** @var int The number of days to show */
    protected $days;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => ['days-header', 'time-grid-header']];

    /** @var DateTime Starting day */
    protected $startDay;

    /**
     * Create a new DaysHeader
     *
     * @param DateTime $startDay
     * @param int $days
     */
    public function __construct(DateTime $startDay, int $days)
    {
        $this->startDay = $startDay;
        $this->days = $days;
    }

    public function assemble(): void
    {
        $dayNames = [
            $this->translate('Mon', 'monday'),
            $this->translate('Tue', 'tuesday'),
            $this->translate('Wed', 'wednesday'),
            $this->translate('Thu', 'thursday'),
            $this->translate('Fri', 'friday'),
            $this->translate('Sat', 'saturday'),
            $this->translate('Sun', 'sunday')
        ];

        $interval = new DateInterval('P1D');
        $today = (new DateTime())->setTime(0, 0);
        $time = clone $this->startDay;
        $dateFormatter = new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE
        );

        for ($i = 0; $i < $this->days; $i++) {
            if ($time == $today) {
                $title = [new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'day-name']),
                    Text::create($this->translate('Today'))
                )];
            } else {
                $title = [
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => 'date']),
                        Text::create($time->format($this->translate('d/m', 'day-name, time')))
                    ),
                    Text::create(' '),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => 'day-name']),
                        Text::create($dayNames[$time->format('N') - 1])
                    )
                ];
            }

            $this->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'column-title', 'title' => $dateFormatter->format($time)]),
                ...$title
            ));

            $time->add($interval);
        }
    }
}
