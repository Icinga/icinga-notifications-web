<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateInterval;
use DateTime;
use Generator;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use ipl\I18n\Translation;
use ipl\Scheduler\RRule;
use ipl\Stdlib\Filter;
use Recurr\Frequency;
use Recurr\Rule;

class Rotation
{
    use Translation;

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
     * Get the ID of the rotation
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->model->id;
    }

    /**
     * Get the schedule ID of the rotation
     *
     * @return int
     */
    public function getScheduleId(): int
    {
        return $this->model->schedule_id;
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
     * Create the base version of the flyout for this rotation
     *
     * @return EntryFlyout
     */
    public function generateEntryInfo(): EntryFlyout
    {
        $rotationMembers = iterator_to_array(
            $this->model->member->with(['contact', 'contactgroup'])
        );

        $flyout = new EntryFlyout();
        $flyout->setMode($this->model->mode)
            ->setRotationMembers($rotationMembers)
            ->setRotationOptions($this->model->options)
            ->setRotationName($this->model->name)
            ->setFirstHandoff($this->model->first_handoff)
            ->generateAndSetRotationInfo();

        return $flyout;
    }

    /**
     * Get the next occurrence of the rotation
     *
     * @param DateTime $after The date after which to yield occurrences
     * @param DateTime $until The date up to which to yield occurrences
     *
     * @return Generator<mixed, Entry>
     */
    public function fetchTimeperiodEntries(DateTime $after, DateTime $until): Generator
    {
        $actualHandoff = null;
        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
            $actualHandoff = $this->model->actual_handoff;
        }

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
                $member = new Member($timeperiodEntry->member->contact->full_name);
            } else {
                $member = new Member($timeperiodEntry->member->contactgroup->name);
                $member->setIcon('users');
            }

            if ($timeperiodEntry->rrule) {
                $recurrRule = new Rule($timeperiodEntry->rrule);
                $limitMultiplier = 1;
                $interval = $recurrRule->getInterval(); // Frequency::DAILY
                if ($recurrRule->getFreq() === Frequency::WEEKLY) {
                    $interval *= 7;
                    if ($recurrRule->getByDay()) {
                        $limitMultiplier = count($recurrRule->getByDay());
                    }
                } // TODO: Yearly? (Those unoptimized single occurrences)

                $before = (clone $after)->setTime(
                    (int) $timeperiodEntry->start_time->format('H'),
                    (int) $timeperiodEntry->start_time->format('i')
                );

                if ($timeperiodEntry->start_time < $before) {
                    $daysSinceLatestHandoff = $timeperiodEntry->start_time->diff($before)->days % $interval;
                    $firstHandoff = (clone $before)->sub(new DateInterval(sprintf('P%dD', $daysSinceLatestHandoff)));
                } else {
                    $firstHandoff = $timeperiodEntry->start_time;
                }

                $rrule = new RRule($timeperiodEntry->rrule);
                $rrule->startAt($firstHandoff);

                $length = $timeperiodEntry->start_time->diff($timeperiodEntry->end_time);
                $limit = (((int) ceil($after->diff($until)->days / $interval)) + 1) * $limitMultiplier;
                foreach ($rrule->getNextRecurrences($firstHandoff, $limit) as $recurrence) {
                    $recurrenceEnd = (clone $recurrence)->add($length);
                    if ($recurrence < $actualHandoff && $recurrenceEnd > $actualHandoff) {
                        $recurrence = $actualHandoff;
                    }

                    if ($recurrence >= $until || $recurrenceEnd <= $after) {
                        // This shouldn't happen often, that's why such entries are just ignored
                        continue;
                    }

                    $occurrence = (new Entry($timeperiodEntry->id))
                        ->setMember($member)
                        ->setStart($recurrence)
                        ->setEnd($recurrenceEnd)
                        ->setUrl(Links::rotationSettings($this->getId(), $this->getScheduleId()));

                    yield $occurrence;
                }
            } else {
                $entry = (new Entry($timeperiodEntry->id))
                    ->setMember($member)
                    ->setStart($timeperiodEntry->start_time)
                    ->setEnd($timeperiodEntry->end_time)
                    ->setUrl(Links::rotationSettings($this->getId(), $this->getScheduleId()));

                yield $entry;
            }
        }
    }
}
