<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\Collection;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Sticker;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Tag;

class CollectionTest extends TestCase
{
    public function testCollectionWithoutAModelSubclassThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Collection member type must be a subclass of');

        new Collection(\LogicException::class);
    }

    public function testCollectionAttachThrowsWithInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $collection = new Collection(Tag::class);
        $collection->attach(new Sticker());
    }

    public function testCollectionDetachThrowsWithInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $collection = new Collection(Tag::class);
        $collection->detach(new Sticker());
    }

    public function testCollectionAttachWithValidTypeWorks(): void
    {
        $collection = new Collection(Tag::class);
        $collection->attach(new Tag());

        $this->assertCount(1, $collection);
    }

    public function testCollectionDetachWithValidTypeWorks(): void
    {
        $tag = new Tag();
        $collection = Collection::create(Tag::class, [$tag]);

        $collection->detach($tag);

        $this->assertCount(0, $collection);
    }

    public function testCollectionFromLoadedReturnsATypedCollection(): void
    {
        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('getIterator')->willReturn(new \ArrayIterator([[]]));

        $dbMock = $this->createMock(Connection::class);
        $dbMock->method('select')
            ->willReturn($selectStmt);

        $query = (new Query())
            ->setDb($dbMock)
            ->setModel(new Tag());

        $collection = Collection::fromLoaded($query);

        $collection->attach(new Tag());

        $this->assertCount(2, $collection);
    }

    public function testCollectionThrowsInCaseOfUnsupportedKeyTypes(): void
    {
        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('getIterator')->willReturn(new \ArrayIterator([['id' => true]]));

        $dbMock = $this->createMock(Connection::class);
        $dbMock->method('select')
            ->willReturn($selectStmt);

        $query = (new Query())
            ->setDb($dbMock)
            ->setModel(new Tag());

        $collection = Collection::fromLoaded($query);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a string or int');

        $collection->count(); // Materializes the query
    }
}
