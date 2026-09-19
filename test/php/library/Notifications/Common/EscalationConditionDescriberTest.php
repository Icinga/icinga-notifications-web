<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\EscalationConditionDescriber;
use ipl\I18n\NoopTranslator;
use ipl\I18n\StaticTranslator;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EscalationConditionDescriberTest extends TestCase
{
    protected function setUp(): void
    {
        StaticTranslator::$instance = new NoopTranslator();
    }

    public static function severitySingleConditionProvider(): array
    {
        return [
            'greater than'              => ['incident_severity>warning', 'Severity exceeds Warning'],
            'greater than or equal'     => ['incident_severity>=warning', 'Severity is at least Warning'],
            'less than'                 => ['incident_severity<err', 'Severity is below Error'],
            'less than or equal'        => ['incident_severity<=err', 'Severity is at most Error'],
            'equal'                     => ['incident_severity=crit', 'Severity equals Critical'],
            'unequal'                   => ['incident_severity!=debug', 'Severity is not Debug']
        ];
    }
    public static function ageProvider(): array
    {
        return [
            'hours plural'                          => ['incident_age>5h', '5 hours'],
            'hours singular'                        => ['incident_age>1h', '1 hour'],
            'minutes plural'                        => ['incident_age>30m', '30 minutes'],
            'minutes singular'                      => ['incident_age>1m', '1 minute'],
            'seconds plural'                        => ['incident_age>30s', '30 seconds'],
            'seconds singular'                      => ['incident_age>1s', '1 second'],
            'seconds converted to minutes'          => ['incident_age>120s', '2 minutes'],
            'seconds converted to hours'            => ['incident_age>3600s', '1 hour'],
            'minutes converted to hours + minutes'  => ['incident_age>90m', '1 hour 30 minutes'],
            'seconds converted to hours + minutes'  => ['incident_age>5400s', '1 hour 30 minutes'],
            'days singular'                         => ['incident_age>24h', '1 day'],
            'days plural'                           => ['incident_age>48h', '2 days'],
            'hours converted to days + hours'       => ['incident_age>35h', '1 day 11 hours'],
            'seconds converted to full breakdown'   => ['incident_age>90061s', '1 day 1 hour 1 minute 1 second']
        ];
    }

    #[DataProvider('severitySingleConditionProvider')]
    public function testSeveritySingleCondition(string $filter, string $expected): void
    {
        $this->assertSame($expected, EscalationConditionDescriber::describe($filter));
    }

    public function testSeverityRangeSortsConditionsRegardlessOfInputOrderInCanonicalOrder(): void
    {
        $this->assertSame(
            'Severity enters the range Debug – Error',
            EscalationConditionDescriber::describe('incident_severity<=err&incident_severity>=debug')
        );
    }

    public function testDescribeReturnsImmediatelyWhenRuleIsEmpty(): void
    {
        $this->assertSame('Immediately', EscalationConditionDescriber::describe(null));
        $this->assertSame('Immediately', EscalationConditionDescriber::describe(''));
    }

    #[DataProvider('ageProvider')]
    public function testAgeDescription(string $filter, string $expected): void
    {
        $this->assertSame("Incident age exceeds $expected", EscalationConditionDescriber::describe($filter));
    }

    public function testCombinesSeverityAndAgeWithAndWhenBothGiven(): void
    {
        $this->assertSame(
            'Severity exceeds Warning and Incident age exceeds 1 day 21 hours',
            EscalationConditionDescriber::describe('incident_age>45h&incident_severity>warning')
        );
    }

    public function testMethodDescribeSupportsStringAsParam(): void
    {
        $this->assertSame(
            'Severity exceeds Warning',
            EscalationConditionDescriber::describe('incident_severity>warning')
        );
    }

    public function testMethodDescribeSupportsRuleAsParam(): void
    {
        $this->assertSame(
            'Severity exceeds Warning',
            EscalationConditionDescriber::describe(Filter::all(
                Filter::greaterThan('incident_severity', 'warning')
            ))
        );
    }
}
