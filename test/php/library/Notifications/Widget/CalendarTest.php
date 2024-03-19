<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Tests\Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Widget\Calendar;
use ipl\I18n\NoopTranslator;
use ipl\I18n\StaticTranslator;
use PHPUnit\Framework\TestCase;

class CalendarTest extends TestCase
{
    protected function setUp(): void
    {
        StaticTranslator::$instance = new NoopTranslator();
    }

    public function testMonthGridStartsAtTheFirstDayOfItsFirstDaysWeek()
    {
        $oldTz = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Berlin');

            $controls = (new Calendar\Controls())->populate([
                'mode' => 'month',
                'month' => '2023-02'
            ])->ensureAssembled();

            $calendar = new Calendar();
            $calendar->setControls($controls);

            $this->assertEquals(
                new \DateTime('2023-01-30T00:00:00+0100'),
                $calendar->getGrid()->getGridStart()
            );
        } finally {
            date_default_timezone_set($oldTz);
        }
    }

    /**
     * @depends testMonthGridStartsAtTheFirstDayOfItsFirstDaysWeek
     */
    public function testMonthGridVisualizesSixWeeks()
    {
        $oldTz = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Berlin');

            $controls = (new Calendar\Controls())->populate([
                'mode' => 'month',
                'month' => '2023-02'
            ])->ensureAssembled();

            $calendar = new Calendar();
            $calendar->setControls($controls);

            $this->assertEquals(
                new \DateTime('2023-03-13T00:00:00+0100'),
                $calendar->getGrid()->getGridEnd()
            );
        } finally {
            date_default_timezone_set($oldTz);
        }
    }
}
