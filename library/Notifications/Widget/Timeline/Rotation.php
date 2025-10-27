<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateInterval;
use DateTime;
use Generator;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use ipl\Html\Attributes;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Scheduler\RRule;
use ipl\Stdlib\Filter;
use ipl\Web\Widget\Icon;
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
     * Generate info that is to be shown when an Entry is hovered
     *
     * @return HtmlElement
     */
    public function generateEntryInfo(): HtmlElement
    {
        $members = [];
        $rotationMembers = $this->model->member
            ->with(['contact', 'contactgroup']);

        foreach ($rotationMembers as $member) {
            if ($member->contact_id !== null) {
                $members[] = new Member($member->contact->full_name);
            } else {
                $memberGroup = new Member($member->contactgroup->name);
                $memberGroup->setIcon('users');
                $members[] = $memberGroup;
            }
        }

        $maxLength = 30;
        $currentLength = 0;
        $memberText = '';
        $memberCount = count($members);
        for ($i = 0; $i < $memberCount; ++$i) {
            $member = $members[$i];
            $currentLength += strlen($member->getName()) + 1;
            if ($currentLength > $maxLength) {
                $remainingMemberCount = $memberCount - $i;
                $remainingMembersText = $remainingMemberCount === $memberCount ?
                    sprintf(
                        $this->translatePlural('%s Member', '%s Members', $remainingMemberCount),
                        $remainingMemberCount
                    ) :
                    sprintf($this->translate(' + %s more'), $remainingMemberCount);
                $memberText .=
                    '<span class="rotation-info-member-count">'
                    . $remainingMembersText .
                    '</span>';

                break;
            }

            if ($memberText !== '') {
                $memberText .= ', ';
            }

            $memberText .= new Icon($member->getIcon()) . $member->getName();
        }

        $modeLabels = [
            'multi' => 'Multi Day',
            'partial' => 'Partial Day',
            '24-7' => '24/7'
        ];

        $mode = $modeLabels[$this->model->mode];
        return new HtmlElement(
            'div',
            Attributes::create(['class' => 'rotation-info']),
            Text::create(
                sprintf(
                    '<span class="rotation-info-name">%s</span> <span class="rotation-info-mode">(%s)</span>
                            <br>%s
                            <br>%s',
                    $this->getName(),
                    $this->translate($mode),
                    $this->generateTimeInfo(),
                    $memberText
                )
            )->setEscaped()
        );
    }

    /**
     * Generate info about start, handoff frequency and time interval
     *
     * @return string
     */
    public function generateTimeInfo(): string
    {
        $options = $this->model->options;
        $mode = $this->model->mode;
        $weekdayNames = [
            1 => $this->translate("Mon"),
            2 => $this->translate("Tue"),
            3 => $this->translate("Wed"),
            4 => $this->translate("Thu"),
            5 => $this->translate("Fri"),
            6 => $this->translate("Sat"),
            7 => $this->translate("Sun")
        ];

        $noneType = \IntlDateFormatter::NONE;
        $shortType = \IntlDateFormatter::SHORT;
        $timeFormatter = new \IntlDateFormatter(\Locale::getDefault(), $noneType, $shortType);
        $dateFormatter = new \IntlDateFormatter(\Locale::getDefault(), $shortType, $noneType);
        $firstHandoff = $dateFormatter->format(DateTime::createFromFormat('Y-m-d', $this->model->first_handoff));

        if (($options['frequency'] ?? null) === 'd') {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every day',
                    'Handoff every %d days',
                    (int) $options['interval']
                ),
                $options['interval']
            );
        } else {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every week',
                    'Handoff every %d weeks',
                    (int) $options['interval']
                ),
                $options['interval']
            );
        }

        if ($mode === "partial") {
            $days = $options["days"];
            $from = $timeFormatter->format(DateTime::createFromFormat('H:i', $options["from"]));
            $to = $timeFormatter->format(DateTime::createFromFormat('H:i', $options["to"]));
            if ($days[count($days) - 1] - $days[0] === (count($days) - 1) && count($days) > 1) {
                $daysText = sprintf(
                    $this->translate(
                        '%s through %s ',
                    ),
                    $weekdayNames[reset($days)],
                    $weekdayNames[end($days)],
                );
            } else {
                $daysText = implode(', ', array_map(function ($day) use ($weekdayNames) {
                    return $weekdayNames[$day];
                }, $days));
            }

            return sprintf(
                $this->translate('%s %s - %s, %s<br>Starts on %s'),
                $daysText,
                $from,
                $to,
                $handoff,
                $firstHandoff
            );
        } elseif ($mode === "multi") {
            $fromDay = $weekdayNames[$options["from_day"]];
            $fromAt = $timeFormatter->format(DateTime::createFromFormat('H:i', $options["from_at"]));
            $toDay = $weekdayNames[$options["to_day"]];
            $toAt = $timeFormatter->format(DateTime::createFromFormat('H:i', $options["to_at"]));
            return sprintf(
                $this->translate("%s %s - %s %s, %s<br>Starts on %s"),
                $fromDay,
                $fromAt,
                $toDay,
                $toAt,
                $handoff,
                $firstHandoff
            );
        } else {
            return "$handoff<br>Starts on $firstHandoff";
        }
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

        $flyoutContent = $this->generateEntryInfo();
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
                        ->setFlyoutContent($flyoutContent)
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
