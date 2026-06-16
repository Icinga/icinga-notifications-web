<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\Source;
use Icinga\Module\Notifications\Model\Source as SourceModel;
use ipl\Sql\Connection;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see Source}.
 *
 * These tests cover the save/delete routing of {@see Source}. All low-level database interactions
 * (insert data, password hashing, update WHERE clause, soft-delete details) are covered in
 * {@see \Tests\Icinga\Module\Notifications\Repository\SourceRepositoryTest}.
 */
class SourceTest extends TestCase
{
    public function testGetNameReturnsNullWhenNotSet(): void
    {
        $this->assertNull((new Source(new SourceModel(), $this->createStub(Connection::class)))->getName());
    }

    public function testGetNameReturnsSetName(): void
    {
        $source = (new Source(new SourceModel(), $this->createStub(Connection::class)))->setName('My Source');

        $this->assertSame('My Source', $source->getName());
    }

    public function testSaveCallsInsertForNewSource(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transaction')->willReturnCallback(fn(callable $cb) => $cb());
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($_, $data) {
                $this->assertSame('y', $data['locked']);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->never())->method('update');

        (new Source(new SourceModel(), $db))
            ->setName('N')
            ->setType('icingadb')
            ->save();
    }

    public function testSaveCallsUpdateForExistingSource(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transaction')->willReturnCallback(fn(callable $cb) => $cb());
        $db->expects($this->never())->method('insert');
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($_, $data) {
                $this->assertSame('y', $data['locked']);

                return $this->createStub(PDOStatement::class);
            });

        $model = new SourceModel(['id' => 5, 'type' => 'icingadb', 'name' => 'N', 'listener_username' => 'u']);
        $model->setNew(false);

        (new Source($model, $db))->setName('Updated')->save();
    }

    public function testSaveThrowsForNewSourceWithoutNameAndType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(new SourceModel(), $this->createStub(Connection::class)))->save();
    }

    public function testSaveThrowsForNewSourceWithoutName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(new SourceModel(), $this->createStub(Connection::class)))->setType('icingadb')->save();
    }

    public function testSaveThrowsForNewSourceWithoutType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source must have a name and type');

        (new Source(new SourceModel(), $this->createStub(Connection::class)))->setName('N')->save();
    }

    public function testDeleteMarksSourceAsDeleted(): void
    {
        $model = new SourceModel([
            'id'                => 5,
            'listener_username' => 'u',
            'deleted'           => 'n'
        ]);
        $model->setNew(false);
        $model->rule = new class {
            public function columns(string $_): array
            {
                return [];
            }
        };

        $db = $this->createMock(Connection::class);
        $db->method('transaction')->willReturnCallback(fn(callable $cb) => $cb());
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($_, $data) {
                $this->assertSame('y', $data['deleted']);
                $this->assertNull($data['listener_username']);

                return $this->createStub(PDOStatement::class);
            });

        (new Source($model, $db))->delete();
    }
}
