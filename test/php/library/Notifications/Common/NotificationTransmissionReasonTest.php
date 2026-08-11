<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\NotificationTransmissionReason;
use ipl\I18n\NoopTranslator;
use ipl\I18n\StaticTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotificationTransmissionReasonTest extends TestCase
{
    public static function backingValueProvider(): array
    {
        return [
            'incident_severity_changed' => [
                NotificationTransmissionReason::INCIDENT_SEVERITY_CHANGED,
                'incident_severity_changed'
            ],
            'escalation_triggered' => [NotificationTransmissionReason::ESCALATION_TRIGGERED, 'escalation_triggered'],
            'opened' => [NotificationTransmissionReason::OPENED, 'opened'],
            'closed' => [NotificationTransmissionReason::CLOSED, 'closed'],
            'muted' => [NotificationTransmissionReason::MUTED, 'muted'],
            'unmuted' => [NotificationTransmissionReason::UNMUTED, 'unmuted'],
            'recipient_role_changed' => [
                NotificationTransmissionReason::RECIPIENT_ROLE_CHANGED,
                'recipient_role_changed'
            ],
            'notified' => [NotificationTransmissionReason::NOTIFIED, 'notified']
        ];
    }

    #[DataProvider('backingValueProvider')]
    public function testBackingValueIsTheDbString(NotificationTransmissionReason $reason, string $expected): void
    {
        $this->assertSame($expected, $reason->value);
    }

    #[DataProvider('backingValueProvider')]
    public function testFromParsesDbString(NotificationTransmissionReason $expected, string $dbValue): void
    {
        $this->assertSame($expected, NotificationTransmissionReason::from($dbValue));
    }

    #[DataProvider('backingValueProvider')]
    public function testGetValueReturnsBackingValue(NotificationTransmissionReason $reason, string $expected): void
    {
        $this->assertSame($expected, $reason->getValue());
    }

    public function testGetLabelCoversAllCases(): void
    {
        $this->assertSame(
            'Incident severity changed',
            NotificationTransmissionReason::INCIDENT_SEVERITY_CHANGED->getLabel()
        );
        $this->assertSame('Escalation triggered', NotificationTransmissionReason::ESCALATION_TRIGGERED->getLabel());
        $this->assertSame('Incident opened', NotificationTransmissionReason::OPENED->getLabel());
        $this->assertSame('Incident closed', NotificationTransmissionReason::CLOSED->getLabel());
        $this->assertSame('Incident muted', NotificationTransmissionReason::MUTED->getLabel());
        $this->assertSame('Incident unmuted', NotificationTransmissionReason::UNMUTED->getLabel());
        $this->assertSame(
            'Recipient role changed',
            NotificationTransmissionReason::RECIPIENT_ROLE_CHANGED->getLabel()
        );
        $this->assertSame('Notified', NotificationTransmissionReason::NOTIFIED->getLabel());
    }

    public function testAllCasesAreCovered(): void
    {
        $this->assertSame(
            [
                NotificationTransmissionReason::INCIDENT_SEVERITY_CHANGED,
                NotificationTransmissionReason::ESCALATION_TRIGGERED,
                NotificationTransmissionReason::CLOSED,
                NotificationTransmissionReason::OPENED,
                NotificationTransmissionReason::MUTED,
                NotificationTransmissionReason::UNMUTED,
                NotificationTransmissionReason::RECIPIENT_ROLE_CHANGED,
                NotificationTransmissionReason::NOTIFIED
            ],
            NotificationTransmissionReason::cases(),
            'A NotificationTransmissionReason case was added or removed — update the test providers and this assertion'
        );
    }
}
