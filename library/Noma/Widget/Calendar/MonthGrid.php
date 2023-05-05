<?php

namespace Icinga\Module\Noma\Widget\Calendar;

use DateInterval;
use DateTime;
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

    protected function assembleGridStep(BaseHtmlElement $content, DateTime $step): void
    {
        $content->addHtml(Text::create($step->format('j')));
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

    protected function getGridArea(int $rowStart, int $rowEnd, int $colStart, int $colEnd): array
    {
        return [$rowStart, $colStart, $rowEnd, $colEnd];
    }

    protected function createGridSteps(): Traversable
    {
        $interval = new DateInterval('P1D');
        $currentDay = clone $this->getGridStart();
        for ($i = 0; $i < 42; $i++) {
            yield $currentDay;

            $currentDay->add($interval);
        }
    }

    protected function createHeader(): BaseHtmlElement
    {
        $dayNames = [
            t('Mon', 'monday'),
            t('Tue', 'tuesday'),
            t('Wed', 'wednesday'),
            t('Thu', 'thursday'),
            t('Fri', 'friday'),
            t('Sat', 'saturday'),
            t('Sun', 'sunday')
        ];

        $header = new HtmlElement('div', Attributes::create(['class' => 'header']));
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

        $time = (new DateTime())->setTime(0, 0);
        $interval = new DateInterval('P1W');
        for ($i = 0; $i < 6; $i++) {
            $sidebar->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'row-title']),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'week-no']),
                    Text::create(sprintf('%s %s', t('CW', 'calendar week'), $time->format('W')))
                )
            ));

            $time->add($interval);
        }

        return $sidebar;
    }

    protected function assemble()
    {
        $this->getAttributes()->add('class', 'month');

        $this->addHtml(
            $this->createHeader(),
            $this->createSidebar(),
            $this->createGrid(),
            $this->createGridOverlay()
        );
    }
}
