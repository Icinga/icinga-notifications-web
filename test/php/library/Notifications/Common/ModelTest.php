<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Common\Model;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    #[DataProvider('unrestorableModelProvider')]
    public function testRestoreErrorHandling(Model $model): void
    {
        $this->expectException(LogicException::class);

        $model->restore();
    }

    #[DataProvider('restorableModelProvider')]
    public function testRestoreActuallyRestores(Model $model): void
    {
        $model->restore();

        $this->assertFalse($model->isMarkedForDeletion(), 'Restored model is still marked for deletion');
        $this->assertFalse($model->deleted, 'Restored model is still soft-deleted');
        $this->assertTrue(isset($model->id), 'Restored model has no ID');
        $this->assertSame(1337, $model->id, 'Restored model has a wrong ID');
    }

    public static function unrestorableModelProvider(): array
    {
        return [
            'New Model' => [(new class () extends Model {
                public function getTableName(): string
                {
                    return 'test';
                }

                public function getKeyName(): string
                {
                    return 'id';
                }

                public function getColumns(): array
                {
                    return ['deleted'];
                }
            })->setNew()],
            'Not Soft-deletable' => [(new class () extends Model {
                public function getTableName(): string
                {
                    return 'test';
                }

                public function getKeyName(): string
                {
                    return 'id';
                }

                public function getColumns(): array
                {
                    return [];
                }
            })->setNew(false)]
        ];
    }

    public static function restorableModelProvider(): array
    {
        return [
            'Soft-deleted' => [(new class (['id' => 1337]) extends Model {
                public function getTableName(): string
                {
                    return 'test';
                }

                public function getKeyName(): string
                {
                    return 'id';
                }

                public function getColumns(): array
                {
                    return ['deleted'];
                }
            })->setNew(false)->delete()],
            'Not New Model' => [(new class (['id' => 1337]) extends Model {
                public function getTableName(): string
                {
                    return 'test';
                }

                public function getKeyName(): string
                {
                    return 'id';
                }

                public function getColumns(): array
                {
                    return ['deleted'];
                }
            })->setNew(false)]
        ];
    }
}
