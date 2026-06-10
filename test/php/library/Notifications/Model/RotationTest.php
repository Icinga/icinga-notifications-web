<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Model;

use Generator;
use Icinga\Module\Notifications\Model\Rotation;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Recurr\Frequency;

/**
 * Tests for {@see Rotation::yieldRecurrenceRules()}.
 *
 * The method translates a rotation's mode and options into a sequence of recurrence rules (and their respective shift
 * durations). It doesn't touch the database, so a rotation built from scratch is all that's needed.
 *
 * Each rule is verified by its frequency, interval, start, the end of its first shift (start + shift duration), its
 * until time and the weekdays it's bound to. The end is asserted instead of the raw `DateInterval` as it's both easier
 * to reason about and unambiguous. Note that the method reuses a single `Rule` instance for the regular sequence, so
 * everything is captured during iteration (see {@see RotationTest::collect()}).
 */
class RotationTest extends TestCase
{
    /**
     * Consume the generator into a comparable, flat representation
     *
     * @param Generator<int, array{0: \Recurr\Rule, 1: \DateInterval}> $rules
     *
     * @return list<array<string, mixed>>
     */
    private function collect(Generator $rules): array
    {
        $collected = [];
        foreach ($rules as $position => [$rule, $shiftDuration]) {
            $start = clone $rule->getStartDate();

            $collected[] = [
                'position'  => $position,
                'freq'      => $rule->getFreq(),
                'interval'  => $rule->getInterval(),
                'start'     => $start->format('Y-m-d H:i'),
                'end'       => (clone $start)->add($shiftDuration)->format('Y-m-d H:i'),
                'until'     => $rule->getUntil()?->format('Y-m-d H:i'),
                'byDay'     => $rule->getByDay()
            ];
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $properties
     * @param int $count
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('recurrenceRuleProvider')]
    public function testYieldRecurrenceRules(array $properties, int $count, array $expected): void
    {
        $rotation = new Rotation($properties);

        $this->assertSame($expected, $this->collect($rotation->yieldRecurrenceRules($count)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: list<array<string, mixed>>}>
     */
    public static function recurrenceRuleProvider(): array
    {
        // 2026-01-05 is a Monday, so the days of that week map to: Mon 05, Tue 06, Wed 07, Thu 08, Fri 09, Sat 10
        return [
            //
            // 24/7 mode: a continuous rotation handing off after a fixed interval
            //
            '24-7 daily, single member' => [
                ['mode' => '24-7', 'first_handoff' => '2026-01-05',
                    'options' => ['interval' => 1, 'frequency' => 'd', 'at' => '09:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::DAILY, 'interval' => 1, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-06 09:00', 'until' => null, 'byDay' => null]
                ]
            ],
            '24-7 weekly, interval 2, single member' => [
                ['mode' => '24-7', 'first_handoff' => '2026-01-05',
                    'options' => ['interval' => 2, 'frequency' => 'w', 'at' => '09:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 2, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-19 09:00', 'until' => null, 'byDay' => null]
                ]
            ],
            // With two members the interval is multiplied by the member count and each gets its own offset shift
            '24-7 daily, two members' => [
                ['mode' => '24-7', 'first_handoff' => '2026-01-05',
                    'options' => ['interval' => 1, 'frequency' => 'd', 'at' => '09:00']],
                2,
                [
                    ['position' => 0, 'freq' => Frequency::DAILY, 'interval' => 2, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-06 09:00', 'until' => null, 'byDay' => null],
                    ['position' => 1, 'freq' => Frequency::DAILY, 'interval' => 2, 'start' => '2026-01-06 09:00',
                        'end' => '2026-01-07 09:00', 'until' => null, 'byDay' => null]
                ]
            ],

            //
            // Partial mode: a daily shift on selected weekdays
            //
            // First handoff is on the first selected day, so the shift starts right away
            'partial Mon-Fri, starting Monday' => [
                ['mode' => 'partial', 'first_handoff' => '2026-01-05',
                    'options' => ['days' => [1, 2, 3, 4, 5], 'interval' => 1, 'from' => '09:00', 'to' => '17:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-05 17:00', 'until' => null,
                        'byDay' => [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR']]
                ]
            ],
            // First handoff is within the range but not on the first day, the shift still starts on that very day
            'partial Mon-Fri, starting Wednesday' => [
                ['mode' => 'partial', 'first_handoff' => '2026-01-07',
                    'options' => ['days' => [1, 2, 3, 4, 5], 'interval' => 1, 'from' => '09:00', 'to' => '17:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-07 09:00',
                        'end' => '2026-01-07 17:00', 'until' => null,
                        'byDay' => [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR']]
                ]
            ],
            // First handoff (Tuesday) is outside the selected days, so it's normalized to the next first day (Monday)
            'partial Mon/Wed/Fri, starting outside the range' => [
                ['mode' => 'partial', 'first_handoff' => '2026-01-06',
                    'options' => ['days' => [1, 3, 5], 'interval' => 1, 'from' => '09:00', 'to' => '17:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-12 09:00',
                        'end' => '2026-01-12 17:00', 'until' => null, 'byDay' => [1 => 'MO', 3 => 'WE', 5 => 'FR']]
                ]
            ],
            // An overnight shift: since the from time is past the to time, the shift ends on the following day
            'partial overnight on Fridays' => [
                ['mode' => 'partial', 'first_handoff' => '2026-01-09',
                    'options' => ['days' => [5], 'interval' => 1, 'from' => '22:00', 'to' => '06:00']],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-09 22:00',
                        'end' => '2026-01-10 06:00', 'until' => null, 'byDay' => [5 => 'FR']]
                ]
            ],
            // Two members alternate weekly (interval * count), the second starts a week after the first
            'partial Mon-Fri, two members' => [
                ['mode' => 'partial', 'first_handoff' => '2026-01-05',
                    'options' => ['days' => [1, 2, 3, 4, 5], 'interval' => 1, 'from' => '09:00', 'to' => '17:00']],
                2,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 2, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-05 17:00', 'until' => null,
                        'byDay' => [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR']],
                    ['position' => 1, 'freq' => Frequency::WEEKLY, 'interval' => 2, 'start' => '2026-01-12 09:00',
                        'end' => '2026-01-12 17:00', 'until' => null,
                        'byDay' => [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR']]
                ]
            ],

            //
            // Multi mode: a single continuous shift spanning multiple days, repeating weekly
            //
            // First handoff is on the from day, the shift spans Mon 09:00 to Fri 17:00
            'multi Mon-Fri, starting on the from day' => [
                ['mode' => 'multi', 'first_handoff' => '2026-01-05',
                    'options' => ['from_day' => 1, 'to_day' => 5, 'from_at' => '09:00', 'to_at' => '17:00',
                        'interval' => 1]],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-05 09:00',
                        'end' => '2026-01-09 17:00', 'until' => null, 'byDay' => null]
                ]
            ],
            // First handoff is within the range, an irregular single occurrence fills the gap until the first regular
            // shift, which is pushed to the following week
            'multi Mon-Fri, starting within the range' => [
                ['mode' => 'multi', 'first_handoff' => '2026-01-07',
                    'options' => ['from_day' => 1, 'to_day' => 5, 'from_at' => '09:00', 'to_at' => '17:00',
                        'interval' => 1]],
                1,
                [
                    // The injected single occurrence (no frequency, an until equal to its start)
                    ['position' => 0, 'freq' => null, 'interval' => 1, 'start' => '2026-01-07 09:00',
                        'end' => '2026-01-09 17:00', 'until' => '2026-01-07 09:00', 'byDay' => null],
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-12 09:00',
                        'end' => '2026-01-16 17:00', 'until' => null, 'byDay' => null]
                ]
            ],
            // A range wrapping across the week boundary (Fri to Mon), spanning Fri 18:00 to Mon 08:00
            'multi wrapping Fri-Mon' => [
                ['mode' => 'multi', 'first_handoff' => '2026-01-09',
                    'options' => ['from_day' => 5, 'to_day' => 1, 'from_at' => '18:00', 'to_at' => '08:00',
                        'interval' => 1]],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-09 18:00',
                        'end' => '2026-01-12 08:00', 'until' => null, 'byDay' => null]
                ]
            ],
            // First handoff (Saturday) is outside the range, so it's normalized to the next from day (Monday)
            'multi Mon-Fri, starting outside the range' => [
                ['mode' => 'multi', 'first_handoff' => '2026-01-10',
                    'options' => ['from_day' => 1, 'to_day' => 5, 'from_at' => '09:00', 'to_at' => '17:00',
                        'interval' => 1]],
                1,
                [
                    ['position' => 0, 'freq' => Frequency::WEEKLY, 'interval' => 1, 'start' => '2026-01-12 09:00',
                        'end' => '2026-01-16 17:00', 'until' => null, 'byDay' => null]
                ]
            ]
        ];
    }

    public function testYieldRecurrenceRulesThrowsOnUnknownMode(): void
    {
        $rotation = new Rotation([
            'mode'          => 'nonsense',
            'first_handoff' => '2026-01-05',
            'options'       => []
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown mode');

        // The generator's body only runs once it's iterated
        iterator_to_array($rotation->yieldRecurrenceRules(1));
    }

    public function testYieldRecurrenceRulesDecodesJsonEncodedOptions(): void
    {
        $rotation = new Rotation([
            'mode'          => '24-7',
            'first_handoff' => '2026-01-05',
            // Options may be stored as a JSON string and must be decoded transparently
            'options'       => '{"interval":1,"frequency":"d","at":"09:00"}'
        ]);

        $this->assertSame(
            [
                ['position' => 0, 'freq' => Frequency::DAILY, 'interval' => 1, 'start' => '2026-01-05 09:00',
                    'end' => '2026-01-06 09:00', 'until' => null, 'byDay' => null]
            ],
            $this->collect($rotation->yieldRecurrenceRules(1))
        );
    }
}
