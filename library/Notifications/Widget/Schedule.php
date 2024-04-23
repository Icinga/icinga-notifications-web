<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use DateTimeZone;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\ScheduleMember;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Module\Notifications\Widget\Calendar\Attendee;
use Icinga\Module\Notifications\Widget\Calendar\Controls;
use Icinga\Module\Notifications\Widget\Calendar\Entry;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class Schedule extends BaseHtmlElement
{
    use BaseTarget;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'schedule'];

    /** @var \Icinga\Module\Notifications\Model\Schedule */
    protected $schedule;

    /** @var Controls */
    protected $controls;

    public function __construct(Controls $controls, ?\Icinga\Module\Notifications\Model\Schedule $schedule)
    {
        $this->schedule = $schedule;
        $this->controls = $controls;
    }

    protected function assembleCalendar(Calendar $calendar): void
    {
        $calendar->setAddEntryUrl(Url::fromPath(
            'notifications/schedule/add-entry',
            ['schedule' => $this->schedule->id]
        ));

        $calendar->setUrl(Url::fromPath(
            'notifications/schedules',
            ['schedule' => $this->schedule->id]
        ));

        $db = Database::get();
        $entries = TimeperiodEntry::on($db)
            ->filter(Filter::equal('timeperiod.schedule.id', $this->schedule->id))
            ->orderBy(['start_time', 'timeperiod_id']);

        $entryFilter = Filter::any(
            Filter::all( // all entries that start in the shown range
                Filter::greaterThanOrEqual('start_time', $calendar->getGrid()->getGridStart()->getTimestamp()),
                Filter::lessThanOrEqual('start_time', $calendar->getGrid()->getGridEnd()->getTimestamp())
            ),
            Filter::all( // all entries that end in the shown range
                Filter::greaterThanOrEqual('end_time', $calendar->getGrid()->getGridStart()->getTimestamp()),
                Filter::lessThanOrEqual('end_time', $calendar->getGrid()->getGridEnd()->getTimestamp())
            ),
            Filter::all( // all entries that start before and end after the shown range
                Filter::lessThanOrEqual('start_time', $calendar->getGrid()->getGridStart()->getTimestamp()),
                Filter::greaterThanOrEqual('end_time', $calendar->getGrid()->getGridEnd()->getTimestamp())
            ),
            Filter::none( // all entries that are repeated and may still occur in the shown range
                Filter::lessThanOrEqual('until_time', $calendar->getGrid()->getGridStart()->getTimestamp())
            ),
            Filter::all( // all entries that are repeated endlessly and already started in the past
                Filter::unlike('until_time', '*'),
                Filter::like('rrule', '*'),
                Filter::lessThanOrEqual('start_time', $calendar->getGrid()->getGridStart()->getTimestamp())
            )
        );

        foreach ($entries->filter($entryFilter) as $entry) {
            $members = ScheduleMember::on($db)
                ->with(['timeperiod', 'contact', 'contactgroup'])
                ->filter(Filter::equal('timeperiod_id', $entry->timeperiod_id))
                ->orderBy(['contact_id', 'contactgroup_id']);

            foreach ($members as $member) {
                if ($member->contact_id !== null) {
                    $attendee = new Attendee($member->contact->full_name);
                } else { // $member->contactgroup_id !== null
                    $attendee = new Attendee($member->contactgroup->name);
                    $attendee->setIcon('users');
                }

                $calendar->addEntry(
                    (new Entry($entry->id))
                        ->setDescription($entry->description)
                        ->setRecurrencyRule($entry->rrule)
                        ->setStart((clone $entry->start_time)->setTimezone(new DateTimeZone($entry->timezone)))
                        ->setEnd((clone $entry->end_time)->setTimezone(new DateTimeZone($entry->timezone)))
                        ->setUrl(Url::fromPath('notifications/schedule/edit-entry', [
                            'id' => $entry->id,
                            'schedule' => $this->schedule->id
                        ]))
                        ->setAttendee($attendee)
                );
            }
        }
    }

    public function assemble()
    {
        $calendar = (new Calendar())
            ->setControls($this->controls);

        $this->setBaseTarget('entry-form');
        if ($this->controls->getBaseTarget() === null) {
            $this->controls->setBaseTarget('_self');
        }

        $scheduleHeader = new HtmlElement('div', Attributes::create(['class' => 'schedule-header']));
        if ($this->schedule !== null) {
            $this->assembleCalendar($calendar);
            $scheduleHeader->addHtml(
                new Link(
                    [
                        new Icon('plus'),
                        t('Add new entry')
                    ],
                    Url::fromPath('notifications/schedule/add-entry', ['schedule' => $this->schedule->id]),
                    ['class' => 'button-link']
                )
            );
        }

        $scheduleContainer = new HtmlElement('div', Attributes::create(['class' => 'schedule-container']));
        $scheduleContainer->addHtml($calendar);
        $scheduleContainer->addHtml(new HtmlElement(
            'div',
            Attributes::create([
                'id' => 'entry-form',
                'class' => 'entry-form container'
            ])
        ));

        $this->addHtml($scheduleHeader, $scheduleContainer);
    }
}
