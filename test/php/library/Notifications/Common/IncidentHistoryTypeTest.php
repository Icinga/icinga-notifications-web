<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\IncidentHistoryType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IncidentHistoryTypeTest extends TestCase
{
    public static function backingValueProvider(): array
    {
        return [
            'incident_severity_changed' => [
                IncidentHistoryType::INCIDENT_SEVERITY_CHANGED,
                'incident_severity_changed'
            ],
            'escalation_triggered' => [IncidentHistoryType::ESCALATION_TRIGGERED, 'escalation_triggered'],
            'opened' => [IncidentHistoryType::OPENED, 'opened'],
            'closed' => [IncidentHistoryType::CLOSED, 'closed'],
            'muted' => [IncidentHistoryType::MUTED, 'muted'],
            'unmuted' => [IncidentHistoryType::UNMUTED, 'unmuted'],
            'recipient_role_changed' => [
                IncidentHistoryType::RECIPIENT_ROLE_CHANGED,
                'recipient_role_changed'
            ],
            'notified' => [IncidentHistoryType::NOTIFIED, 'notified'],
            'rule_matched' => [IncidentHistoryType::RULE_MATCHED, 'rule_matched']
        ];
    }

    #[DataProvider('backingValueProvider')]
    public function testBackingValueIsTheDbString(IncidentHistoryType $type, string $expected): void
    {
        $this->assertSame($expected, $type->value);
    }

    #[DataProvider('backingValueProvider')]
    public function testFromParsesDbString(IncidentHistoryType $expected, string $dbValue): void
    {
        $this->assertSame($expected, IncidentHistoryType::from($dbValue));
    }

    #[DataProvider('backingValueProvider')]
    public function testGetValueReturnsBackingValue(IncidentHistoryType $type, string $expected): void
    {
        $this->assertSame($expected, $type->getValue());
    }

    public function testGetLabelCoversAllCases(): void
    {
        $this->assertSame(
            'Incident severity changed',
            IncidentHistoryType::INCIDENT_SEVERITY_CHANGED->getLabel()
        );
        $this->assertSame('Escalation triggered', IncidentHistoryType::ESCALATION_TRIGGERED->getLabel());
        $this->assertSame('Incident opened', IncidentHistoryType::OPENED->getLabel());
        $this->assertSame('Incident closed', IncidentHistoryType::CLOSED->getLabel());
        $this->assertSame('Incident muted', IncidentHistoryType::MUTED->getLabel());
        $this->assertSame('Incident unmuted', IncidentHistoryType::UNMUTED->getLabel());
        $this->assertSame(
            'Recipient role changed',
            IncidentHistoryType::RECIPIENT_ROLE_CHANGED->getLabel()
        );
        $this->assertSame('Notified', IncidentHistoryType::NOTIFIED->getLabel());
        $this->assertSame('Rule matched', IncidentHistoryType::RULE_MATCHED->getLabel());
    }

    public function testAllCasesAreCovered(): void
    {
        $this->assertSame(
            [
                IncidentHistoryType::INCIDENT_SEVERITY_CHANGED,
                IncidentHistoryType::ESCALATION_TRIGGERED,
                IncidentHistoryType::CLOSED,
                IncidentHistoryType::OPENED,
                IncidentHistoryType::MUTED,
                IncidentHistoryType::UNMUTED,
                IncidentHistoryType::RECIPIENT_ROLE_CHANGED,
                IncidentHistoryType::NOTIFIED,
                IncidentHistoryType::RULE_MATCHED
            ],
            IncidentHistoryType::cases(),
            'An IncidentHistoryType case was added or removed — update the test providers and this assertion'
        );
    }
}
