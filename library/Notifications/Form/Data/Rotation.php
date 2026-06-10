<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use LogicException;
use Recurr\Frequency;
use Recurr\Rule;

readonly class Rotation
{
    /**
     * @param ?int $id The primary database key value, NULL for new rotations
     * @param int $scheduleId The ID of the schedule a rotation belongs to
     * @param int $priority The piority of the rotation inside the schedule's timeline
     * @param string $name The name of the rotation
     * @param string $mode The mode of the rotation
     * @param array $options The mode options
     * @param array<array{0: 'contact'|'contact_group', 1: int}> $members Members of the rotation
     * @param DateTimeImmutable $firstHandoff When the rotation starts
     * @param ?DateTimeImmutable $previousHandoff Set if {@see RotationConfigForm::EXPERIMENTAL_OVERRIDES} is true
     * @param ?DateTimeImmutable $nextHandoff Set if {@see RotationConfigForm::EXPERIMENTAL_OVERRIDES} is true
     * @param ?DateTimeImmutable $previousShift Set if {@see RotationConfigForm::EXPERIMENTAL_OVERRIDES} is true
     */
    public function __construct(
        public ?int $id,
        public int $scheduleId,
        public int $priority,
        public string $name,
        public string $mode,
        public array $options,
        public array $members,
        public DateTimeImmutable $firstHandoff,
        public ?DateTimeImmutable $previousHandoff = null,
        public ?DateTimeImmutable $nextHandoff = null,
        public ?DateTimeImmutable $previousShift = null
    ) {
    }

    /**
     * Yield recurrence rules based on this configuration
     *
     * @param ?int $count The number of rules to yield. If NULL, derived from the number of members
     *
     * @return Generator<int, array{0: Rule, 1: DateInterval}>
     */
    public function yieldRecurrenceRules(?int $count = null): Generator
    {
        $rule = new Rule();
        $firstRotationOffset = null;
        $count ??= count($this->members);

        switch ($this->mode) {
            case '24-7':
                $interval = (int) $this->options['interval'];
                $firstHandoff = self::parseDateAndTime(
                    $this->firstHandoff->getTimezone(),
                    $this->firstHandoff->format('Y-m-d'),
                    $this->options['at']
                );

                if ($this->options['frequency'] === 'd') {
                    $frequency = Frequency::DAILY;
                    $shiftDuration = new DateInterval(sprintf('P%dD', $interval));
                } else {
                    $frequency = Frequency::WEEKLY;
                    $shiftDuration = new DateInterval(sprintf('P%dW', $interval));
                }

                $rule->setFreq($frequency);
                $rule->setInterval($interval * $count);

                $ruleSeq = range(0, $count - 1);
                $rotationOffset = $shiftDuration;

                break;
            case 'partial':
                $days = array_map('intval', $this->options['days']);
                $interval = (int) $this->options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);
                $rule->setByDay(array_intersect_key(
                    [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'],
                    array_flip($days)
                ));

                $firstHandoff = self::parseDateAndTime(
                    $this->firstHandoff->getTimezone(),
                    $this->firstHandoff->format('Y-m-d'),
                    $this->options['from']
                );
                $firstHandoffDay = (int) $firstHandoff->format('N');
                if ($firstHandoffDay !== $days[0] && in_array($firstHandoffDay, $days, true)) {
                    // In case the first handoff is in the range, but doesn't start at the first day of the
                    // rotation, the first shift is shorter than regular so the first rotation offset differs
                    $firstRotationOffset = $firstHandoff->diff(
                        (clone $firstHandoff)->add(new DateInterval(sprintf(
                            'P%dD',
                            $days[0] > $firstHandoff->format('N')
                                ? $days[0] - $firstHandoff->format('N')
                                : 7 - $firstHandoff->format('N') + $days[0]
                        )))
                    );
                } elseif ($firstHandoffDay !== $days[0]) {
                    // Normalize the first handoff to the first day of the shift in case it's outside the range
                    $firstHandoff->add(new DateInterval(sprintf(
                        'P%dD',
                        $days[0] > $firstHandoffDay
                            ? $days[0] - $firstHandoffDay
                            : 7 - $firstHandoffDay + $days[0]
                    )));
                }

                $shiftEnd = self::parseDateAndTime(
                    $this->firstHandoff->getTimezone(),
                    $firstHandoff->format('Y-m-d'),
                    $this->options['to']
                );
                if ($firstHandoff >= $shiftEnd) {
                    $shiftEnd->add(new DateInterval('P1D'));
                }

                $rotationOffset = new DateInterval('P1W');
                $shiftDuration = $firstHandoff->diff($shiftEnd);

                $ruleSeq = [];
                for ($i = 0; $i < $count; $i++) {
                    array_push($ruleSeq, ...array_fill(0, $interval, $i));
                }

                break;
            case 'multi':
                $fromDay = (int) $this->options['from_day'];
                $toDay = (int) $this->options['to_day'];
                $interval = (int) $this->options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);

                $ruleSeq = [];
                for ($i = 0; $i < $count; $i++) {
                    array_push($ruleSeq, ...array_fill(0, $interval, $i));
                }

                $firstHandoff = self::parseDateAndTime(
                    $this->firstHandoff->getTimezone(),
                    $this->firstHandoff->format('Y-m-d'),
                    $this->options['from_at']
                );
                $firstHandoffDay = (int) $firstHandoff->format('N');

                if (
                    $fromDay < $toDay && ($firstHandoffDay < $fromDay || $firstHandoffDay > $toDay)
                    || $toDay < $fromDay && ($firstHandoffDay < $fromDay && $firstHandoffDay > $toDay)
                    || $firstHandoffDay === $toDay && $toDay !== $fromDay
                    && $firstHandoff >= self::parseDateAndTime(
                        $this->firstHandoff->getTimezone(),
                        $this->firstHandoff->format('Y-m-d'),
                        $this->options['to_at']
                    )
                ) {
                    // Normalize the first handoff to the first day of the shift in case it's outside the range
                    $firstHandoff->add(new DateInterval(sprintf(
                        'P%dD',
                        $fromDay > $firstHandoffDay
                            ? $fromDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $fromDay
                    )));
                } elseif ($firstHandoffDay !== $fromDay) {
                    // In case the first handoff is in the range, but doesn't start at the first day of the rotation,
                    // the first shift is shorter than the regular interval and separately injected into the rule seq
                    $firstEntryStart = clone $firstHandoff;
                    if ($firstHandoffDay === $toDay) {
                        $firstEntryStart->setTime(0, 0);
                    }

                    $firstRule = new Rule(null, $firstEntryStart);
                    $firstRule->setUntil($firstEntryStart);

                    $firstShiftEnd = (clone $firstEntryStart)->add(new DateInterval(sprintf(
                        'P%dD',
                        $toDay >= $firstHandoffDay
                            ? $toDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $toDay
                    )));
                    if (isset($this->nextHandoff) && $firstShiftEnd > $this->nextHandoff) {
                        $firstShiftDuration = $firstEntryStart->diff($this->nextHandoff);
                    } else {
                        $firstShiftDuration = $firstEntryStart->diff(
                            self::parseDateAndTime(
                                $firstHandoff->getTimezone(),
                                $firstShiftEnd->format('Y-m-d'),
                                $this->options['to_at']
                            )
                        );
                    }

                    yield 0 => [$firstRule, $firstShiftDuration];

                    // The irregular first shift has been injected now, so the first regular shift needs
                    // to be pushed to the end of the rule sequence so that the pattern continues normally
                    $ruleSeq[] = array_shift($ruleSeq);

                    $firstHandoff = (clone $firstHandoff)->add(new DateInterval(sprintf(
                        'P%dD',
                        $fromDay > $firstHandoffDay
                            ? $fromDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $fromDay
                    )));
                }

                $shiftDuration = $firstHandoff->diff(self::parseDateAndTime( // returns the first end datetime
                    $this->firstHandoff->getTimezone(),
                    (clone $firstHandoff)
                        ->add(new DateInterval(sprintf(
                            'P%dD',
                            $toDay > $fromDay
                                ? $toDay - $fromDay
                                : 7 - $fromDay + $toDay
                        )))->format('Y-m-d'),
                    $this->options['to_at']
                ));

                $rotationOffset = new DateInterval('P1W');

                break;
            default:
                throw new LogicException('Unknown mode');
        }

        $singleOccurrences = [];
        foreach ($ruleSeq as $position) {
            $rule->setStartDate($firstHandoff);

            if (isset($this->nextHandoff)) {
                $remainingHandoffs = self::calculateRemainingHandoffs($rule, $shiftDuration, $this->nextHandoff);

                $lastHandoff = array_shift($remainingHandoffs);
                if (! empty($remainingHandoffs)) {
                    [$gapStart, $gapEnd] = $remainingHandoffs[0];

                    $singleOccurrences[] = [$position, [
                        (new Rule(null, $gapStart))->setFreq(Frequency::YEARLY)->setUntil($gapStart),
                        $gapStart->diff($gapEnd)
                    ]];
                }

                if ($lastHandoff !== null) {
                    $rule->setUntil($lastHandoff);
                } else {
                    continue; // Skip occurrences that have no chance to happen
                }
            }

            if ($firstRotationOffset !== null) {
                $firstHandoff = (clone $firstHandoff)->add($firstRotationOffset);
                $firstRotationOffset = null;
            } else {
                $firstHandoff = (clone $firstHandoff)->add($rotationOffset);
            }

            yield $position => [$rule, $shiftDuration];
        }

        // After regular occurrences were yielded, single occurrences are yielded in the order they were generated
        foreach ($singleOccurrences as [$key, $value]) {
            yield $key => $value;
        }
    }

    /**
     * Get the last possible handoff before the given date
     *
     * @param Rule $rrule
     * @param DateInterval $shiftDuration
     * @param DateTimeInterface $before
     *
     * @return array{0: ?DateTime, 1?: array{0: DateTime, 1: DateTime}}
     *
     * @throws LogicException If the frequency is not supported
     */
    public static function calculateRemainingHandoffs(
        Rule $rrule,
        DateInterval $shiftDuration,
        DateTimeInterface $before
    ): array {
        if ($rrule->getStartDate() >= $before) {
            // No time passed yet, the first occurrence is in the future
            return [null];
        }

        if ($rrule->getFreq() === Frequency::YEARLY) {
            // There is only once chance that this frequency is used: For single occurrences
            $lastShiftEnd = (clone $rrule->getStartDate())->add($shiftDuration);
            if ($lastShiftEnd > $before) {
                $lastShiftEnd = clone $before;
            }

            // This relies on the fact that the calling code only knows about repeating rules, it
            // cannot update single occurrences, so $lastHandoff is null here to replace it instead
            return [null, [$rrule->getStartDate(), $lastShiftEnd]];
        } elseif ($rrule->getFreq() === Frequency::DAILY) {
            $interval = $rrule->getInterval();
        } elseif ($rrule->getFreq() === Frequency::WEEKLY) {
            $interval = $rrule->getInterval() * 7;
        } else {
            throw new LogicException('Unsupported frequency');
        }

        // $before is based on new changes, so it's required to synchronize it with the given RRULE
        $beforeNormalized = (clone $before)->setTime(
            (int) $rrule->getStartDate()->format('H'),
            (int) $rrule->getStartDate()->format('i')
        );

        $daysSinceLatestHandoff = $rrule->getStartDate()->diff($beforeNormalized)->days % $interval;
        $lastHandoff = (clone $beforeNormalized)
            ->sub(new DateInterval(sprintf('P%dD', $daysSinceLatestHandoff)));

        $result = [];

        $byDay = $rrule->getByDay();
        if (empty($byDay)) {
            $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
            if ($lastShiftEnd > $before) {
                if ($lastHandoff < $before) {
                    // The last shift is still ongoing, so report it as the single remaining handoff
                    $result[] = [clone $lastHandoff, (clone $lastHandoff)->add($lastHandoff->diff($before))];
                }

                // Return the occurrence before the last, as it overlaps with the given date otherwise
                $lastHandoff->sub(new DateInterval(sprintf('P%dD', $interval)));
            }
        } else {
            // If this RRULE is based on a partial day configuration, forward to the very last possible shift
            $byDay = array_intersect([
                1 => 'MO',
                2 => 'TU',
                3 => 'WE',
                4 => 'TH',
                5 => 'FR',
                6 => 'SA',
                7 => 'SU'
            ], $byDay);

            $daysInTheFirstShift = max(array_keys($byDay)) - $rrule->getStartDate()->format('N');
            $lastHandoff->add(new DateInterval(sprintf('P%dD', $daysInTheFirstShift)));
            for ($i = 0; $i < $daysInTheFirstShift; $i++) {
                if (isset($byDay[$lastHandoff->format('N')]) && $lastHandoff < $before) {
                    $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
                    if ($lastShiftEnd < $before) {
                        break;
                    } else {
                        // The last shift is still ongoing, so report it as the single remaining handoff
                        $result[] = [clone $lastHandoff, (clone $lastHandoff)->add($lastHandoff->diff($before))];
                    }
                }

                $lastHandoff->sub(new DateInterval('P1D'));
            }
        }

        if ($lastHandoff < $rrule->getStartDate()) {
            $lastHandoff = null;
        }

        array_unshift($result, $lastHandoff);

        return $result;
    }

    /**
     * Parse the given date and time expression
     *
     * @param DateTimeZone $timezone Timezone to use for the resulting DateTime object
     * @param ?string $date A date in the format Y-m-d, default is the current day
     * @param ?string $time The time in the format H:i, default is midnight
     *
     * @return DateTime
     */
    public static function parseDateAndTime(
        DateTimeZone $timezone,
        ?string $date = null,
        ?string $time = null
    ): DateTime {
        $format = '';
        $expression = '';

        if ($date !== null) {
            $format = 'Y-m-d';
            $expression = $date;
        }

        if ($time !== null) {
            if ($date !== null) {
                $format .= ' ';
                $expression .= ' ';
            }

            $format .= 'H:i';
            $expression .= $time;
        }

        if (! $format) {
            return new DateTime('today', $timezone);
        }

        $datetime = DateTime::createFromFormat($format, $expression, $timezone);

        if ($datetime === false) {
            $datetime = new DateTime('today', $timezone);
        } elseif ($time === null) {
            $datetime->setTime(0, 0);
        }

        return $datetime;
    }
}
