<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use EmptyIterator;
use Icinga\Application\Hook;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Widget\Icon;
use PHPUnit\Framework\TestCase;
use Traversable;

class TestSource implements SourceHook
{
    public function getSourceLabel(): string
    {
        return '';
    }

    public function getSourceIcon(): Icon
    {
        return new Icon('');
    }

    public function assertValidCondition(Condition $condition): void
    {
    }

    public function enrichCondition(Condition $condition): void
    {
    }

    public function getJsonPaths(string ...$columns): array
    {
        return [];
    }

    public function getValueSuggestions(string $column, string $searchTerm, Chain $searchFilter): Traversable
    {
        return new EmptyIterator();
    }

    public function getColumnSuggestions(string $searchTerm): Traversable
    {
        return new EmptyIterator();
    }
}

// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class SourceHookLocatorTest extends TestCase
{
    public function setUp(): void
    {
        Hook::register('Notifications\\V2\\TestSource', 'stub', TestSource::class, true);
    }

    public function testForTypeReturnsTheRegisteredHook()
    {
        $hook = SourceHookLocator::forType('test');

        $this->assertInstanceOf(TestSource::class, $hook);
    }

    public function testForTypeReturnsNullWhenNoHookIsRegistered()
    {
        $this->assertNull(SourceHookLocator::forType('unregistered'));
    }
}
