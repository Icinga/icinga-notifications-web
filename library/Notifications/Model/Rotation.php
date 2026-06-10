<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateInterval;
use DateTime;
use DateTimeZone;
use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Util\Json;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Contract\RetrieveBehavior;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use LogicException;
use Recurr\Frequency;
use Recurr\Rule;

/**
 * Rotation
 *
 * @property int $id
 * @property int $schedule_id
 * @property ?int $priority
 * @property string $name
 * @property string $mode
 * @property string|array $options
 * @property string $first_handoff
 * @property DateTime $actual_handoff
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Schedule $schedule
 * @property Query|RotationMember $member
 * @property Query|Timeperiod $timeperiod
 */
class Rotation extends Model
{
    public function getTableName(): string
    {
        return 'rotation';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'schedule_id',
            'priority',
            'name',
            'mode',
            'options',
            'first_handoff',
            'actual_handoff',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'schedule_id'       => t('Schedule'),
            'priority'          => t('Priority'),
            'name'              => t('Name'),
            'mode'              => t('Mode'),
            'first_handoff'     => t('First Handoff'),
            'actual_handoff'    => t('Actual Handoff'),
            'changed_at'        => t('Changed At')
        ];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('schedule', Schedule::class);

        $relations->hasMany('member', RotationMember::class);

        $relations->hasOne('timeperiod', Timeperiod::class)
            ->setForeignKey('owned_by_rotation_id');
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp([
            'actual_handoff',
            'changed_at'
        ]));
        $behaviors->add(new BoolCast(['deleted']));
        $behaviors->add(new class implements RetrieveBehavior {
            public function retrieve(Model $model): void
            {
                /** @var Rotation $model */
                if (isset($model->options) && is_string($model->options)) {
                    $model->options = Json::decode($model->options, true);
                }
            }
        });
    }

    /**
     * Delete rotation and all related references
     *
     * @return void
     */
    public function delete(): void
    {
        $db = Database::get();
        $transactionStarted = false;
        if (! $db->inTransaction()) {
            $transactionStarted = true;
            $db->beginTransaction();
        }

        if ($this->timeperiod instanceof Timeperiod) {
            $timeperiodId = $this->timeperiod->id;
        } else {
            $timeperiodId = $this->timeperiod->columns('id')->first()->id;
        }

        $changedAt = (int) (new DateTime())->format("Uv");
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];

        $db->update('timeperiod_entry', $markAsDeleted, ['timeperiod_id = ?' => $timeperiodId, 'deleted = ?' => 'n']);
        $db->update('timeperiod', $markAsDeleted, ['id = ?' => $timeperiodId]);

        $db->update(
            'rotation_member',
            $markAsDeleted + ['position' => null],
            ['rotation_id = ?' => $this->id, 'deleted = ?' => 'n']
        );

        $db->update(
            'rotation',
            $markAsDeleted + ['priority' => null, 'first_handoff' => null],
            ['id = ?' => $this->id]
        );

        $requirePriorityUpdate = true;
        if (RotationConfigForm::EXPERIMENTAL_OVERRIDES) {
            $rotations = self::on($db)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('schedule_id', $this->schedule_id))
                ->filter(Filter::equal('priority', $this->priority))
                ->first();

            $requirePriorityUpdate = $rotations === null;
        }

        if ($requirePriorityUpdate) {
            $affectedRotations = self::on($db)
                ->columns('id')
                ->filter(Filter::equal('schedule_id', $this->schedule_id))
                ->filter(Filter::greaterThan('priority', $this->priority))
                ->orderBy('priority', SORT_ASC);

            foreach ($affectedRotations as $rotation) {
                $db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $rotation->id]
                );
            }
        }

        if ($transactionStarted) {
            $db->commitTransaction();
        }
    }

    /**
     * Parse the given date and time expression
     *
     * @param ?string $date A date in the format Y-m-d, default is the current day
     * @param ?string $time The time in the format H:i, default is midnight
     *
     * @return DateTime
     */
    public function parseDateAndTime(?string $date = null, ?string $time = null): DateTime
    {
        $format = '';
        $expression = '';
        $timezone = isset($this->schedule->timezone) ? new DateTimeZone($this->schedule->timezone) : null;

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

    /**
     * Yield recurrence rules based on the form's values
     *
     * @param int $count The number of rules to yield
     *
     * @return Generator<int, array{0: Rule, 1: DateInterval}>
     */
    public function yieldRecurrenceRules(int $count): Generator
    {
        $rule = new Rule();
        $firstRotationOffset = null;

        // TODO: Should this be a behavior's job?
        $options = is_string($this->options) ? Json::decode($this->options, true) : $this->options;
        switch ($this->mode) {
            case '24-7':
                $interval = (int) $options['interval'];
                $firstHandoff = $this->parseDateAndTime($this->first_handoff, $options['at']);

                if ($options['frequency'] === 'd') {
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
                $days = array_map('intval', $options['days']);
                $interval = (int) $options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);
                $rule->setByDay(array_intersect_key(
                    [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'],
                    array_flip($days)
                ));

                $firstHandoff = $this->parseDateAndTime($this->first_handoff, $options['from']);
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

                $shiftEnd = $this->parseDateAndTime($firstHandoff->format('Y-m-d'), $options['to']);
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
                $fromDay = (int) $options['from_day'];
                $toDay = (int) $options['to_day'];
                $interval = (int) $options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);

                $ruleSeq = [];
                for ($i = 0; $i < $count; $i++) {
                    array_push($ruleSeq, ...array_fill(0, $interval, $i));
                }

                $firstHandoff = $this->parseDateAndTime($this->first_handoff, $options['from_at']);
                $firstHandoffDay = (int) $firstHandoff->format('N');

                if (
                    $fromDay < $toDay && ($firstHandoffDay < $fromDay || $firstHandoffDay > $toDay)
                    || $toDay < $fromDay && ($firstHandoffDay < $fromDay && $firstHandoffDay > $toDay)
                    || $firstHandoffDay === $toDay && $toDay !== $fromDay
                    && $firstHandoff >= $this->parseDateAndTime($this->first_handoff, $options['to_at'])
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
                            $this->parseDateAndTime($firstShiftEnd->format('Y-m-d'), $options['to_at'])
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

                $shiftDuration = $firstHandoff->diff($this->parseDateAndTime( // returns the first end datetime
                    (clone $firstHandoff)
                        ->add(new DateInterval(sprintf(
                            'P%dD',
                            $toDay > $fromDay
                                ? $toDay - $fromDay
                                : 7 - $fromDay + $toDay
                        )))->format('Y-m-d'),
                    $options['to_at']
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
     * @param DateTime $before
     *
     * @return array{0: ?DateTime, 1?: array{0: DateTime, 1: DateTime}}
     *
     * @throws LogicException If the frequency is not supported
     */
    public static function calculateRemainingHandoffs(Rule $rrule, DateInterval $shiftDuration, DateTime $before): array
    {
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
        $lastHandoff = (clone $beforeNormalized)->sub(new DateInterval(sprintf('P%dD', $daysSinceLatestHandoff)));

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
}
