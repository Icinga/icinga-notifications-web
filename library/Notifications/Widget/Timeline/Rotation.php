<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateTime;
use Generator;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Widget\Calendar\Attendee;
use Icinga\Module\Notifications\Widget\Calendar\Entry;
use ipl\Stdlib\Filter;

class Rotation
{
    /** @var \Icinga\Module\Notifications\Model\Rotation */
    protected $model;

    /**
     * Create a new Rotation
     *
     * @param \Icinga\Module\Notifications\Model\Rotation $model
     */
    public function __construct(\Icinga\Module\Notifications\Model\Rotation $model)
    {
        $this->model = $model;
    }

    /**
     * Get the name of the rotation
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->model->name;
    }

    /**
     * Get the priority of the rotation
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->model->priority;
    }

    /**
     * Get the actual handoff of the rotation
     *
     * @return DateTime
     */
    public function getActualHandoff(): DateTime
    {
        return $this->model->actual_handoff;
    }

    /**
     * Get the next occurrence of the rotation
     *
     * @param DateTime $after The date after which to yield occurrences
     *
     * @return Generator<mixed, Entry>
     */
    public function fetchTimeperiodEntries(DateTime $after): Generator
    {
        $entries = $this->model->timeperiod->timeperiod_entry
            ->with(['member.contact', 'member.contactgroup'])
            ->filter(Filter::all(
                Filter::any(
                    Filter::like('rrule', '*'), // It's either a repeating entry
                    Filter::greaterThan('end_time', $after) // Or one whose end time is still visible
                ),
                Filter::any(
                    Filter::unlike('until_time', '*'), // It's either an infinitely repeating entry
                    Filter::greaterThanOrEqual('until_time', $after) // Or one which isn't over yet
                )
            ));
        foreach ($entries as $timeperiodEntry) {
            if ($timeperiodEntry->member->contact->id !== null) {
                $attendee = new Attendee($timeperiodEntry->member->contact->full_name);
            } else {
                $attendee = new Attendee($timeperiodEntry->member->contactgroup->name);
                $attendee->setIcon('users');
            }

            $entry = new Entry($timeperiodEntry->id);
            $entry->setAttendee($attendee);
            $entry->setStart($timeperiodEntry->start_time);
            $entry->setEnd($timeperiodEntry->end_time);
            $entry->setRecurrencyRule($timeperiodEntry->rrule);
            $entry->setUrl(Links::rotationSettings($this->model->id, $this->model->schedule_id));

            yield $entry;
        }
    }
}
