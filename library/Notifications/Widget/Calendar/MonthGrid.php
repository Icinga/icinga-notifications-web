<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Widget\TimeGrid\BaseGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\GridStep;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use Traversable;

class MonthGrid extends BaseGrid
{
    public function setGridStart(DateTime $start): BaseGrid
    {
        if ($start->format('j:H:i:s') !== '1:00:00:00') {
            throw new InvalidArgumentException('Start is not the first of the month or not midnight');
        }

        while ($start->format('D') !== 'Mon') {
            $start->sub(new DateInterval('P1D'));
        }

        return parent::setGridStart($start);
    }

    protected function calculateGridEnd(): DateTime
    {
        return (clone $this->getGridStart())->add(new DateInterval('P42D'));
    }

    protected function getRowStartModifier(): int
    {
        return 2; // The month grid needs the first row for other things
    }

    protected function getSectionsPerStep(): int
    {
        return 5;
    }

    protected function getNoOfVisuallyConnectedHours(): int
    {
        return 7 * 24;
    }

    protected function createGridSteps(): Traversable
    {
        $interval = new DateInterval('P1D');
        $currentDay = clone $this->getGridStart();
        for ($i = 0; $i < 42; $i++) {
            $nextDay = (clone $currentDay)->add($interval);

            yield (new GridStep(
                $currentDay,
                $nextDay,
                $i % 7,
                (int) floor($i / 7)
            ))->addHtml(Text::create($currentDay->format('j')));

            $currentDay = $nextDay;
        }
    }

    protected function createHeader(): BaseHtmlElement
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

        $header = new HtmlElement('div', Attributes::create(['class' => 'time-grid-header']));
        foreach ($dayNames as $dayName) {
            $header->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'column-title']),
                new HtmlElement('span', Attributes::create(['class' => 'day-name']), Text::create($dayName))
            ));
        }

        return $header;
    }

    protected function createSidebar(): BaseHtmlElement
    {
        $sidebar = new HtmlElement('div', Attributes::create(['class' => 'sidebar']));

        $time = clone $this->getGridStart();
        $interval = new DateInterval('P1W');
        for ($i = 0; $i < 6; $i++) {
            $sidebar->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'row-title']),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'week-no']),
                    Text::create(sprintf(
                        '%s %s',
                        $this->translate('CW', 'calendar week'),
                        $time->format('W')
                    ))
                )
            ));

            $time->add($interval);
        }

        return $sidebar;
    }

    protected function assemble()
    {
        $this->addHtml(
            $this->createHeader(),
            $this->createSidebar(),
            $this->createGrid(),
            $this->createGridOverlay()
        );
    }
}
