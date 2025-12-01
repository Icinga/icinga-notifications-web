<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateTime;
use DateTimeZone;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;
use Locale;

class EntryFlyout extends BaseHtmlElement
{
    use Translation;

    /** @var ?Member Member who is on duty during the entry's timespan */
    protected ?Member $activeMember = null;

    /** @var ?string Mode of the entry's rotation can be "partial", "multi" or "24-7" */
    protected ?string $mode = null;

    /** @var ?array All members of the entry's rotation */
    protected ?array $rotationMembers = null;

    /** @var ?array Rotation start time, end time, and frequency */
    protected ?array $rotationOptions = null;

    /** @var ?string First handoff of the rotation */
    protected ?string $firstHandoff = null;

    /** @var ?string Name of the entry's rotation */
    protected ?string $rotationName = null;

    /** @var ?ValidHtml Information about timespan, frequency and the first handoff */
    protected ?ValidHtml $timeInfo = null;

    /** @var ?ValidHtml Information about name and mode of the rotation */
    protected ?ValidHtml $nameInfo = null;

    /** @var DateTimeZone The display timezone */
    protected DateTimeZone $displayTimezone;

    /**
     * @param DateTimeZone $displayTimezone The display timezone
     */
    public function __construct(DateTimeZone $displayTimezone)
    {
        $this->displayTimezone = $displayTimezone;
    }

    /**
     * Return a copy of this flyout for the given entry
     *
     * @param Entry $entry
     *
     * @return static
     */
    public function forEntry(Entry $entry): static
    {
        if (! isset($this->timeInfo)) {
            $this->generateAndSetRotationInfo($entry->getScheduleTimezone());
        }

        $flyout = clone $this;
        $flyout->activeMember = $entry->getMember();

        return $flyout;
    }

    /**
     * Set the rotation mode of the entry
     *
     * @param string $mode
     *
     * @return $this
     */
    public function setMode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Set rotation members of the entry
     *
     * @param array $members
     *
     * @return $this
     */
    public function setRotationMembers(array $members): static
    {
        $this->rotationMembers = $members;

        return $this;
    }

    /**
     * Set the options of the entry's rotation
     *
     * @param array $options
     *
     * @return $this
     */
    public function setRotationOptions(array $options): static
    {
        $this->rotationOptions = $options;

        return $this;
    }

    /**
     * Set the first handoff date of the entry's rotation
     *
     * @param string $firstHandoff
     *
     * @return $this
     */
    public function setFirstHandoff(string $firstHandoff): static
    {
        $this->firstHandoff = $firstHandoff;

        return $this;
    }

    /**
     * Set the name of the entry's rotation
     *
     * @param string $rotationName
     *
     * @return $this
     */
    public function setRotationName(string $rotationName): static
    {
        $this->rotationName = $rotationName;

        return $this;
    }

    public function assemble(): void
    {
        if (count($this->rotationMembers) > 1) {
            $memberList = new HtmlElement('span', Attributes::create(['class' => 'rotation-info-member-list']));
            $activeMemberIndex = 0;
            foreach ($this->rotationMembers as $i => $member) {
                if (
                    $member->contact_id !== null &&
                    $member->contact->full_name === $this->activeMember->getName()
                ) {
                    $activeMemberIndex = $i;
                    break;
                } elseif (
                    $member->contactgroup_id !== null &&
                    $member->contactgroup->name === $this->activeMember->getName()
                ) {
                    $activeMemberIndex = $i;
                    break;
                }
            }

            $membersOrdered = array_merge(
                array_slice($this->rotationMembers, $activeMemberIndex + 1),
                array_slice($this->rotationMembers, 0, $activeMemberIndex + 1)
            );

            $hiddenMemberCount = count(array_splice($membersOrdered, 2));
            $visibleNames = (new HtmlDocument())->setSeparator(', ');
            foreach ($membersOrdered as $member) {
                if ($member->contact_id !== null) {
                    $visibleNames->addHtml(
                        new HtmlElement(
                            'span',
                            null,
                            new Icon('user'),
                            Text::create($member->contact->full_name)
                        )
                    );
                } else {
                    $visibleNames->addHtml(
                        new HtmlElement(
                            'span',
                            null,
                            new Icon('users'),
                            Text::create($member->contactgroup->name)
                        )
                    );
                }
            }

            $memberList->addHtml($visibleNames);
            if ($hiddenMemberCount > 0) {
                $memberList->addHtml(
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-member-count']]),
                        Text::create(sprintf($this->translate(' + %d more'), $hiddenMemberCount))
                    )
                );
            }
        }

        $this->addHtml(
            $this->nameInfo,
            $this->timeInfo
        );

        if (isset($memberList)) {
            $this->addHtml(
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'rotation-info-members']),
                    FormattedString::create(
                        $this->translate('Next in order %s'),
                        $memberList
                    )
                )
            );
        }

        $this->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'rotation-info-on-duty']),
                FormattedString::create(
                    $this->translate('%s is on duty'),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => 'rotation-info-active-member-name']),
                        new Icon($this->activeMember->getIcon()),
                        Text::create($this->activeMember->getName())
                    )
                )
            )
        );
    }

    /**
     * Shift the weekday if the entry starts on an earlier or later weekday in the display timezone
     *
     * @param int $day
     * @param int $shift
     *
     * @return int
     */
    protected function shiftDay(int $day, int $shift): int
    {
        return ((($day - 1 + $shift) % 7) + 7) % 7 + 1;
    }

    /**
     * Shift the whole weekdays array if the entries start on an earlier or later weekday in the display timezone
     *
     * @param array $days
     * @param int   $shift
     *
     * @return array
     */
    protected function shiftDays(array $days, int $shift): array
    {
        if ($shift === 0) {
            return $days;
        }

        $out = [];
        foreach ($days as $d) {
            $out[] = $this->shiftDay($d, $shift);
        }

        return $out;
    }

    /**
     * Check whether the passed days are in consecutive order
     *
     * @param array $days
     *
     * @return bool
     */
    protected function daysAreConsecutiveInOrder(array $days): bool
    {
        $count = count($days);

        if ($count < 2) {
            return false;
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $expectedNext = ($days[$i] % 7) + 1;
            if ($days[$i + 1] != $expectedNext) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate the end date for a multi day rotation using the first handoff and the weekdays for the start and end
     *
     * @param DateTime $firstHandoff
     * @param int      $fromDay
     * @param int      $toDay
     *
     * @return DateTime
     */
    protected function calculateMultiEndDate(DateTime $firstHandoff, int $fromDay, int $toDay): DateTime
    {
        $durationDays = ($toDay - $fromDay + 7) % 7;
        if ($durationDays === 0) {
            $durationDays = 7;
        }

        return (clone $firstHandoff)->modify("+$durationDays days");
    }

    /**
     * Generate and save the part of the entry flyout, that remains equal for all entries of the rotation
     *
     * @param DateTimeZone $scheduleTimezone The timezone the schedule is created in
     *
     * @return $this
     */
    protected function generateAndSetRotationInfo(DateTimeZone $scheduleTimezone): static
    {
        $this->setTag('div');
        $this->setAttribute('class', 'rotation-info');

        $weekdayNames = [
            1 => $this->translate("Mon"),
            2 => $this->translate("Tue"),
            3 => $this->translate("Wed"),
            4 => $this->translate("Thu"),
            5 => $this->translate("Fri"),
            6 => $this->translate("Sat"),
            7 => $this->translate("Sun")
        ];

        $noneType = IntlDateFormatter::NONE;
        $shortType = IntlDateFormatter::SHORT;
        $startTime = match ($this->mode) {
            '24-7'    => $this->rotationOptions['at'],
            'partial' => $this->rotationOptions['from'],
            'multi'   => $this->rotationOptions['from_at']
        };
        $timeFormatter = new IntlDateFormatter(Locale::getDefault(), $noneType, $shortType, $this->displayTimezone);
        $dateFormatter = new IntlDateFormatter(Locale::getDefault(), $shortType, $noneType, $this->displayTimezone);

        $firstHandoffDt = DateTime::createFromFormat(
            'Y-m-d H:i',
            $this->firstHandoff . ' ' . $startTime,
            $scheduleTimezone
        );

        $displayFirstHandoffDt = (clone $firstHandoffDt)->setTimezone($this->displayTimezone);

        // Determine whether the first handoff date shifted to the previous day (-1), stayed on the same day (0),
        // or moved to the next day (1) after converting to the display timezone.
        $shift = $displayFirstHandoffDt->format('Ymd') <=> $firstHandoffDt->format('Ymd');

        $firstHandoff = $dateFormatter->format($firstHandoffDt);

        if (($this->rotationOptions['frequency'] ?? null) === 'd') {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every day',
                    'Handoff every %d days',
                    (int) $this->rotationOptions['interval']
                ),
                $this->rotationOptions['interval']
            );
        } else {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every week',
                    'Handoff every %d weeks',
                    (int) $this->rotationOptions['interval']
                ),
                $this->rotationOptions['interval']
            );
        }

        if (new DateTime('now', $this->displayTimezone) < $displayFirstHandoffDt) {
            $startText = $this->translate('Starts on %s');
        } else {
            $startText = $this->translate('Started on %s');
        }

        $startTime = $timeFormatter->format(DateTime::createFromFormat(
            'H:i',
            $startTime,
            $scheduleTimezone
        ));
        $firstHandoffInfo = new HtmlElement(
            'span',
            Attributes::create(['class' => 'rotation-info-start']),
            FormattedString::create(
                $startText,
                new HtmlElement('time', null, Text::create($firstHandoff))
            )
        );

        if ($this->mode === "24-7") {
            // Include handoff daytime in 24-7 rotations
            $handoff .= sprintf($this->translate(' at %s'), $startTime);
        }

        $handoffInterval = new HtmlElement(
            'span',
            Attributes::create(['class' => ['rotation-info-interval']]),
            Text::create($handoff)
        );

        $timeInfo = new HtmlElement(
            'div',
            Attributes::create(['class' => ['rotation-info-time']])
        );

        if ($this->mode === "partial") {
            $days = $this->shiftDays($this->rotationOptions["days"], $shift);
            $to = $timeFormatter->format(DateTime::createFromFormat(
                'H:i',
                $this->rotationOptions["to"],
                $scheduleTimezone
            ));
            if ($this->daysAreConsecutiveInOrder($days)) {
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

            $this->timeInfo = $timeInfo->addHtml(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['days-interval-wrapper']]),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-days']]),
                        Text::create(sprintf('%s %s - %s, ', $daysText, $startTime, $to))
                    ),
                    $handoffInterval
                )
            )->addHtml($firstHandoffInfo);
        } elseif ($this->mode === "multi") {
            $firstHandoffEndDt = DateTime::createFromFormat(
                'Y-m-d H:i',
                $this->calculateMultiEndDate(
                    $firstHandoffDt,
                    $this->rotationOptions["from_day"],
                    $this->rotationOptions["to_day"]
                )->format('Y-m-d') . ' ' . $this->rotationOptions['to_at'],
                $scheduleTimezone
            );

            $displayFirstHandoffEndDt = (clone $firstHandoffEndDt)->setTimezone($this->displayTimezone);

            // Determine whether the end day of the first handoff shifted to the previous day (-1), stayed on the
            // same day (0), or moved to the next day (1) after converting to the display timezone.
            $endShift = $displayFirstHandoffEndDt->format('Ymd') <=> $firstHandoffEndDt->format('Ymd');

            $fromDay = $weekdayNames[$this->shiftDay($this->rotationOptions["from_day"], $shift)];
            $toDay = $weekdayNames[$this->shiftDay($this->rotationOptions["to_day"], $endShift)];
            $toAt = $timeFormatter->format(DateTime::createFromFormat(
                'H:i',
                $this->rotationOptions["to_at"],
                $scheduleTimezone
            ));

            $this->timeInfo = $timeInfo->addHtml(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['days-interval-wrapper']]),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-days']]),
                        Text::create(sprintf('%s %s - %s %s, ', $fromDay, $startTime, $toDay, $toAt))
                    ),
                    $handoffInterval
                )
            )->addHtml($firstHandoffInfo);
        } else {
            $this->timeInfo = $timeInfo->addHtml($handoffInterval)->addHtml($firstHandoffInfo);
        }

        $mode = match ($this->mode) {
            'multi' => $this->translate('Multi Day'),
            'partial' => $this->translate('Partial Day'),
            '24-7' => $this->translate('24/7')
        };

        $this->nameInfo = new HtmlElement(
            'span',
            Attributes::create(['class' => 'rotation-info-name']),
            Text::create($this->rotationName),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'rotation-info-mode']),
                Text::create(" ($mode)")
            )
        );

        return $this;
    }
}
