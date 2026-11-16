<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use ArrayIterator;
use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Model\NotificationHistory;
use ipl\Html\HtmlDocument;
use ipl\Orm\ResultSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoadMoreTest extends TestCase
{
    /**
     * @param list<int> $ids
     * @param bool $hasSeparator
     *
     * @return void
     */
    #[DataProvider('pages')]
    public function testPageSeparatorRequiresResults(array $ids, bool $hasSeparator): void
    {
        $list = new class () extends HtmlDocument {
            use LoadMore {
                getIterator as public;
            }
        };
        $list->setPageNumber(2)->setPageSize(2);

        $rows = array_map(static fn(int $id): NotificationHistory => new NotificationHistory(['id' => $id]), $ids);
        $result = new ResultSet(new ArrayIterator($rows));

        $this->assertSame($rows, iterator_to_array($list->getIterator($result)));
        $this->assertSame($hasSeparator, str_contains($list->render(), 'page-separator'));
    }

    /**
     * @return array<string, array{list<int>, bool}>
     */
    public static function pages(): array
    {
        return [
            'empty page'    => [[], false],
            'nonempty page' => [[1, 2], true],
        ];
    }
}
