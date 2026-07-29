<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\Source;
use Icinga\Module\Notifications\Repository\SourceRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Icinga\Module\Notifications\Lib\CallableInterface;

class SourceTest extends TestCase
{
    public function testGetNameReturnsNullWhenNotSet(): void
    {
        $this->assertNull((new Source(
            fn() => null,
            $this->createStub(SourceRepository::class),
            '',
            null,
            null,
            null
        ))->getName());
    }

    public function testGetNameReturnsSetName(): void
    {
        $source = (new Source(
            fn() => null,
            $this->createStub(SourceRepository::class),
            '',
            null,
            null,
            null
        ))->setName('My Source');

        $this->assertSame('My Source', $source->getName());
    }

    public function testSaveCallsInsertForNewSource(): void
    {
        $db = $this->createMock(SourceRepository::class);
        $db->expects($this->once())->method('create')->with(
            new \Icinga\Module\Notifications\Form\Data\Source(
                null,
                'icingadb',
                'N',
                'icingadb',
                null,
                null,
                true
            )
        )->willReturn(5);

        $transactionWrapper = $this->createMock(CallableInterface::class);
        $transactionWrapper
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        (new Source(
            $transactionWrapper(...),
            $db,
            'icingadb',
            null,
            null,
            null
        ))
            ->setName('N')
            ->setType('icingadb')
            ->save();
    }

    public function testSaveInternallySetsTheIdSoThatDeleteWorks(): void
    {
        $db = $this->createMock(SourceRepository::class);
        $db->expects($this->once())->method('create')->with(
            new \Icinga\Module\Notifications\Form\Data\Source(
                null,
                'icingadb',
                'N',
                'icingadb',
                null,
                null,
                true
            )
        )->willReturn(5);
        $db->expects($this->once())->method('delete')->with(5);

        $transactionWrapper = $this->createMock(CallableInterface::class);
        $transactionWrapper
            ->method('__invoke')
            ->willReturnCallback(function ($callback) {
                return $callback();
            });

        $source = new Source(
            $transactionWrapper(...),
            $db,
            'icingadb',
            null,
            'icingadb',
            'N'
        );

        $source->save();
        $source->delete();
    }

    public function testSaveCallsUpdateForExistingSource(): void
    {
        $db = $this->createMock(SourceRepository::class);
        $db->expects($this->once())->method('update')->with(
            new \Icinga\Module\Notifications\Form\Data\Source(
                5,
                'icingadb',
                'Updated',
                'u',
                null,
                null,
                true
            )
        );

        $transactionWrapper = $this->createMock(CallableInterface::class);
        $transactionWrapper
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        (new Source(
            $transactionWrapper(...),
            $db,
            'u',
            5,
            'icingadb',
            'N'
        ))->setName('Updated')->save();
    }

    public function testSaveThrowsForNewSourceWithoutNameAndType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(
            fn() => null,
            $this->createStub(SourceRepository::class),
            '',
            null,
            null,
            null
        ))->save();
    }

    public function testSaveThrowsForNewSourceWithoutName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(
            fn() => null,
            $this->createStub(SourceRepository::class),
            '',
            null,
            null,
            null
        ))->setType('icingadb')->save();
    }

    public function testSaveThrowsForNewSourceWithoutType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(
            fn() => null,
            $this->createStub(SourceRepository::class),
            '',
            null,
            null,
            null
        ))->setName('N')->save();
    }
}
