<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use Traversable;

class DayGrid extends BaseGrid
{
    public function setGridStart(DateTime $start): BaseGrid
    {
        if ($start->format('H:i:s') !== '00:00:00') {
            throw new InvalidArgumentException('Start is not midnight');
        }

        return parent::setGridStart($start);
    }

    protected function getMaximumRowSpan(): int
    {
        return 28;
    }

    protected function calculateGridEnd(): DateTime
    {
        return (clone $this->getGridStart())->add(new DateInterval('P1D'));
    }

    protected function getNoOfVisuallyConnectedHours(): int
    {
        return 24;
    }

    protected function getGridArea(int $rowStart, int $rowEnd, int $colStart, int $colEnd): array
    {
        return [$colStart, $rowStart, $colEnd, $rowEnd];
    }

    protected function createGridSteps(): Traversable
    {
        $interval = new DateInterval('P1D');
        $hourStartsAt = clone $this->getGridStart();
        for ($i = 0; $i < 24; $i++) {
            yield $hourStartsAt;

            $hourStartsAt->add($interval);
        }
    }

    protected function createHeader(): BaseHtmlElement
    {
        $header = new HtmlElement('div', Attributes::create(['class' => 'header']));
        $dayNames = [
            'Mon' => t('Mon', 'monday'),
            'Tue' => t('Tue', 'tuesday'),
            'Wed' => t('Wed', 'wednesday'),
            'Thu' => t('Thu', 'thursday'),
            'Fri' => t('Fri', 'friday'),
            'Sat' => t('Sat', 'saturday'),
            'Sun' => t('Sun', 'sunday')
        ];

        $currentDay = clone $this->getGridStart();
        $interval = new DateInterval('P1D');
        $header->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'column-title']),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'day-name']),
                Text::create($dayNames[$currentDay->format('D')])
            ),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'day-number']),
                Text::create($currentDay->format('d'))
            )
        ));

        return $header;
    }

    protected function createSidebar(): BaseHtmlElement
    {
        $sidebar = new HtmlElement('div', Attributes::create(['class' => 'sidebar']));

        $time = (new DateTime())->setTime(0, 0);
        $interval = new DateInterval('PT1H');
        for ($i = 0; $i < 24; $i++) {
            $sidebar->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'row-title']),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'hour']),
                    Text::create($time->format('H:i'))
                )
            ));

            $time->add($interval);
        }

        return $sidebar;
    }

    protected function assemble()
    {
        $this->getAttributes()->add('class', 'day');

        $this->addHtml(
            $this->createHeader(),
            $this->createSidebar(),
            $this->createGrid(),
            $this->createGridOverlay()
        );
    }
}
