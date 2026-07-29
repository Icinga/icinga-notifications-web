<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\NotificationTransmissionState;
use ipl\I18n\NoopTranslator;
use ipl\I18n\StaticTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotificationTransmissionStateTest extends TestCase
{
    public static function backingValueProvider(): array
    {
        return [
            'sent' => [NotificationTransmissionState::SENT, 'sent'],
            'failed' => [NotificationTransmissionState::FAILED, 'failed'],
            'pending' => [NotificationTransmissionState::PENDING, 'pending'],
            'suppressed' => [NotificationTransmissionState::SUPPRESSED, 'suppressed']
        ];
    }

    #[DataProvider('backingValueProvider')]
    public function testBackingValueIsTheDbString(NotificationTransmissionState $state, string $expected): void
    {
        $this->assertSame($expected, $state->value);
    }

    #[DataProvider('backingValueProvider')]
    public function testFromParsesDbString(NotificationTransmissionState $expected, string $dbValue): void
    {
        $this->assertSame($expected, NotificationTransmissionState::from($dbValue));
    }

    #[DataProvider('backingValueProvider')]
    public function testGetValueReturnsBackingValue(NotificationTransmissionState $state, string $expected): void
    {
        $this->assertSame($expected, $state->getValue());
    }

    public function testGetLabelCoversAllCases(): void
    {
        $this->assertSame(
            'Successfully sent notification',
            NotificationTransmissionState::SENT->getLabel()
        );
        $this->assertSame('Failed to send notification', NotificationTransmissionState::FAILED->getLabel());
        $this->assertSame('Pending notification', NotificationTransmissionState::PENDING->getLabel());
        $this->assertSame('Suppressed notification', NotificationTransmissionState::SUPPRESSED->getLabel());
    }

    public static function iconClassProvider(): array
    {
        return [
            'sent' => [NotificationTransmissionState::SENT, 'sent'],
            'failed' => [NotificationTransmissionState::FAILED, 'failed'],
            'pending' => [NotificationTransmissionState::PENDING, 'pending'],
            'suppressed' => [NotificationTransmissionState::SUPPRESSED, 'suppressed']
        ];
    }

    #[DataProvider('iconClassProvider')]
    public function testGetIconRendersCorrectStateClass(
        NotificationTransmissionState $state,
        string $stateClass
    ): void {
        $this->assertStringContainsString($stateClass, $state->getIcon()->render());
    }

    #[DataProvider('backingValueProvider')]
    public function testGetIconTitleContainsStateTitle(NotificationTransmissionState $state, string $_): void
    {
        $this->assertStringContainsString($state->getLabel(), $state->getIcon()->render());
    }

    public function testAllCasesAreCovered(): void
    {
        $this->assertSame(
            [
                NotificationTransmissionState::SENT,
                NotificationTransmissionState::FAILED,
                NotificationTransmissionState::PENDING,
                NotificationTransmissionState::SUPPRESSED
            ],
            NotificationTransmissionState::cases(),
            'A NotificationTransmissionState case was added or removed — update the test providers and this assertion'
        );
    }
}
