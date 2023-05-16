<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use Traversable;

class WeekGrid extends BaseGrid
{
    public function setGridStart(DateTime $start): BaseGrid
    {
        if ($start->format('w:H:i:s') !== '1:00:00:00') {
            throw new InvalidArgumentException('Start is not a monday or not midnight');
        }

        return parent::setGridStart($start);
    }

    protected function calculateGridEnd(): DateTime
    {
        return (clone $this->getGridStart())->add(new DateInterval('P7D'));
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
        for ($i = 0; $i < 7 * 24; $i++) {
            if ($i > 0 && $i % 7 === 0) {
                $hourStartsAt = clone $this->getGridStart();
                $hourStartsAt->add(new DateInterval(sprintf('PT%dH', $i / 7)));
            }

            yield $hourStartsAt;

            $hourStartsAt->add($interval);
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

        $currentDay = clone $this->getGridStart();
        $interval = new DateInterval('P1D');
        foreach ($dayNames as $dayName) {
            $header->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'column-title']),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'day-name']),
                    Text::create($dayName)
                ),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'day-number']),
                    Text::create($currentDay->format('d'))
                )
            ));

            $currentDay->add($interval);
        }

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
        $this->getAttributes()->add('class', 'week');

        $this->addHtml(
            $this->createHeader(),
            $this->createSidebar(),
            $this->createGrid(),
            $this->createGridOverlay()
        );
    }
}
