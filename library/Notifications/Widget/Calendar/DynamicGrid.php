<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateInterval;
use DateTime;
use IntlDateFormatter;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use Locale;
use LogicException;
use Traversable;

class DynamicGrid extends BaseGrid
{
    /** @var int The number of days to show */
    protected $days = 7;

    /** @var ?BaseHtmlElement This grid's sidebar */
    protected $sideBar;

    public function setGridStart(DateTime $start): BaseGrid
    {
        if ($start->format('H:i:s') !== '00:00:00') {
            throw new InvalidArgumentException('Start is not midnight');
        }

        return parent::setGridStart($start);
    }

    /**
     * Set the number of days to show
     *
     * @param int $days
     *
     * @return $this
     */
    public function setDays(int $days): self
    {
        $this->days = $days;

        return $this;
    }

    /**
     * Add the given element as row to the sidebar
     *
     * @param BaseHtmlElement $row
     *
     * @return $this
     */
    public function addToSideBar(BaseHtmlElement $row): self
    {
        $row->addAttributes(['class' => 'row-title']);
        $this->sideBar()->addHtml($row);

        return $this;
    }

    protected function calculateGridEnd(): DateTime
    {
        return (clone $this->getGridStart())->add(new DateInterval(sprintf('P%dD', $this->days)));
    }

    protected function getNoOfVisuallyConnectedHours(): int
    {
        return $this->days * 24;
    }

    protected function getSectionsPerStep(): int
    {
        return self::INFINITE;
    }

    protected function getMaximumRowSpan(): int
    {
        return 1;
    }

    /**
     * Get this grid's sidebar
     *
     * @return BaseHtmlElement
     */
    protected function sideBar(): BaseHtmlElement
    {
        if ($this->sideBar === null) {
            $this->sideBar = new HtmlElement('div', Attributes::create(['class' => 'sidebar']));
        }

        return $this->sideBar;
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

        $interval = new DateInterval('P1D');
        $today = (new DateTime())->setTime(0, 0);
        $time = clone $this->getGridStart();
        $dateFormatter = new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE
        );

        $header = new HtmlElement('div', Attributes::create(['class' => 'header']));
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

            $header->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'column-title', 'title' => $dateFormatter->format($time)]),
                ...$title
            ));

            $time->add($interval);
        }

        return $header;
    }

    protected function createGridSteps(): Traversable
    {
        $interval = new DateInterval('P1D');
        $dayStartsAt = clone $this->getGridStart();
        $primaryRows = count($this->sideBar());
        if ($primaryRows === 0) {
            throw new LogicException('At least one row in the sidebar is required');
        }

        for ($y = 0; $y < $primaryRows; $y++) {
            for ($x = 0; $x < $this->days; $x++) {
                $nextDay = (clone $dayStartsAt)->add($interval);

                yield new GridStep($dayStartsAt, $nextDay, $x, $y);

                $dayStartsAt = $nextDay;
            }

            $dayStartsAt = clone $this->getGridStart();
        }
    }

    protected function assemble()
    {
        $this->style->addFor($this, [
            '--primaryColumns' => $this->days,
            '--columnsPerStep' => 48,
            '--rowsPerStep' => 1
        ]);

        $overlay = $this->createGridOverlay();
        if ($overlay->isEmpty()) {
            $this->style->addFor($this, [
                '--primaryRows' => count($this->sideBar())
            ]);
        }

        $this->addHtml(
            $this->createHeader(),
            $this->sideBar(),
            $this->createGrid(),
            $overlay
        );
    }
}
