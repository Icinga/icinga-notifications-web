<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\Severity;
use ipl\I18n\NoopTranslator;
use ipl\I18n\StaticTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    protected function setUp(): void
    {
        StaticTranslator::$instance = new NoopTranslator();
    }

    /**
     * @return array<string, array{Severity, string}>
     */
    public static function backingValueProvider(): array
    {
        return [
            'ok' => [Severity::OK, 'ok'],
            'critical' => [Severity::CRITICAL, 'crit'],
            'warning' => [Severity::WARNING, 'warning'],
            'error' => [Severity::ERROR, 'err'],
            'debug' => [Severity::DEBUG, 'debug'],
            'info' => [Severity::INFO, 'info'],
            'alert' => [Severity::ALERT, 'alert'],
            'emergency' => [Severity::EMERGENCY, 'emerg'],
            'notice' => [Severity::NOTICE, 'notice']
        ];
    }

    /**
     * @return array<string, array{Severity, string, string}>
     */
    public static function iconProvider(): array
    {
        return [
            'ok' => [Severity::OK, 'fa-circle-check', 'severity-ok'],
            'critical' => [Severity::CRITICAL, 'fa-circle-exclamation', 'severity-crit'],
            'warning' => [Severity::WARNING, 'fa-triangle-exclamation', 'severity-warning'],
            'error' => [Severity::ERROR, 'fa-circle-xmark', 'severity-err'],
            'debug' => [Severity::DEBUG, 'fa-bug-slash', 'severity-debug'],
            'info' => [Severity::INFO, 'fa-circle-info', 'severity-info'],
            'alert' => [Severity::ALERT, 'fa-bell', 'severity-alert'],
            'emergency' => [Severity::EMERGENCY, 'fa-bullhorn', 'severity-emerg'],
            'notice' => [Severity::NOTICE, 'fa-envelope', 'severity-notice']
        ];
    }

    #[DataProvider('backingValueProvider')]
    public function testBackingValueIsTheDbString(Severity $severity, string $expected): void
    {
        $this->assertSame($expected, $severity->value);
    }

    #[DataProvider('backingValueProvider')]
    public function testFromParsesDbString(Severity $expected, string $dbValue): void
    {
        $this->assertSame($expected, Severity::from($dbValue));
    }

    public function testGetLabelCoversAllCases(): void
    {
        $this->assertSame('Ok', Severity::OK->getLabel());
        $this->assertSame('Critical', Severity::CRITICAL->getLabel());
        $this->assertSame('Warning', Severity::WARNING->getLabel());
        $this->assertSame('Error', Severity::ERROR->getLabel());
        $this->assertSame('Debug', Severity::DEBUG->getLabel());
        $this->assertSame('Information', Severity::INFO->getLabel());
        $this->assertSame('Alert', Severity::ALERT->getLabel());
        $this->assertSame('Emergency', Severity::EMERGENCY->getLabel());
        $this->assertSame('Notice', Severity::NOTICE->getLabel());
    }

    #[DataProvider('iconProvider')]
    public function testGetIconRendersCorrectIconClass(Severity $severity, string $iconClass, string $_): void
    {
        $this->assertStringContainsString($iconClass, $severity->getIcon()->render());
    }

    #[DataProvider('iconProvider')]
    public function testGetIconRendersCorrectSeverityClass(Severity $severity, string $_, string $severityClass): void
    {
        $this->assertStringContainsString($severityClass, $severity->getIcon()->render());
    }

    #[DataProvider('backingValueProvider')]
    public function testGetIconTitleContainsSeverityLabel(Severity $severity, string $_): void
    {
        $expected = sprintf('Severity set to %s', $severity->getLabel());
        $this->assertStringContainsString($expected, $severity->getIcon()->render());
    }

    public function testAllCasesAreCovered(): void
    {
        $this->assertSame(
            [
                Severity::OK,
                Severity::CRITICAL,
                Severity::WARNING,
                Severity::ERROR,
                Severity::DEBUG,
                Severity::INFO,
                Severity::ALERT,
                Severity::EMERGENCY,
                Severity::NOTICE
            ],
            Severity::cases(),
            'A Severity case was added or removed — update the test providers and this assertion'
        );
    }
}
