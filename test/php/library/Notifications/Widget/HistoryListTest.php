<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Widget;

use ArrayIterator;
use DateTime;
use Icinga\Module\Notifications\Common\NotificationTransmissionState;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\Widget\ItemList\HistoryList;
use Icinga\Test\BaseTestCase;
use ipl\Html\HtmlDocument;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;

class HistoryListTest extends BaseTestCase
{
    public function testFullPageKeepsLookaheadOutOfTheList(): void
    {
        $html = $this->historyList(3, 2)->render();

        $this->assertSame(2, substr_count($html, 'class="subject"'));
        $this->assertStringNotContainsString('id=3', $html);
        $this->assertStringContainsString('Load More', $html);
        $this->assertStringContainsString('data-no-icinga-ajax', $html);
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('#page-2', $html);
    }

    public function testExactlyFullLastPageHasNoLoadMoreLink(): void
    {
        $html = $this->historyList(2, 2)->render();

        $this->assertSame(2, substr_count($html, 'class="subject"'));
        $this->assertStringNotContainsString('Load More', $html);
    }

    public function testPartialLastPageHasNoLoadMoreLink(): void
    {
        $html = $this->historyList(1, 2)->render();

        $this->assertSame(1, substr_count($html, 'class="subject"'));
        $this->assertStringNotContainsString('Load More', $html);
    }

    public function testEmptyHistoryHasNoLoadMoreLink(): void
    {
        $html = $this->historyList(0, 2)->render();

        $this->assertStringContainsString('No items found.', $html);
        $this->assertStringNotContainsString('Load More', $html);
    }

    public function testRebuiltPagesKeepSeparatorsAndAdvanceToTheNextPage(): void
    {
        $html = $this->historyList(5, 4)->setPageSize(2)->render();

        $this->assertSame(4, substr_count($html, 'class="subject"'));
        $this->assertSame(1, substr_count($html, 'class="list-item page-separator"'));
        $this->assertStringContainsString('id="page-2"', $html);
        $this->assertStringContainsString('page=3', $html);
    }

    public function testCompactPageContainsListItemsWithoutANestedList(): void
    {
        $list = $this->historyList(3, 2)->setPageNumber(2);
        $html = (new HtmlDocument())->addFrom($list)->render();

        $this->assertStringNotContainsString('<ul', $html);
        $this->assertSame(2, substr_count($html, 'class="subject"'));
        $this->assertStringContainsString('id="page-2"', $html);
        $this->assertStringContainsString('page=3', $html);
    }

    public function testEmptyCompactPageHasNoSeparatorOrLoadMoreLink(): void
    {
        $list = $this->historyList(0, 2)->setPageNumber(2);
        $html = (new HtmlDocument())->addFrom($list)->render();

        $this->assertStringNotContainsString('page-separator', $html);
        $this->assertStringNotContainsString('Load More', $html);
    }

    public function testLoadMorePreservesFilterAndControls(): void
    {
        $list = $this->historyList(3, 2)
            ->setLoadMoreUrl(Url::fromPath('notifications/history', [
                'before'   => 42,
                'limit'    => 2,
                'sort'     => 'notification_history.state,notification_history.triggered_at desc',
                'viewmode' => 'minimal',
            ])->setFilter(Filter::equal('contact_id', 7)))
            ->setItemLayoutClass(MinimalItemLayout::class);
        $html = html_entity_decode($list->render());

        $this->assertStringContainsString('before=42', $html);
        $this->assertStringContainsString('limit=2', $html);
        $this->assertStringContainsString('sort=notification_history.state', $html);
        $this->assertStringContainsString('viewmode=minimal', $html);
        $this->assertStringContainsString('contact_id=7', $html);
        $this->assertStringContainsString('page=2', $html);
    }

    /**
     * Create a history list with a bounded result set
     *
     * @param int $rows The number of available rows, including lookahead
     * @param int $limit The number of visible rows
     *
     * @return HistoryList
     */
    protected function historyList(int $rows, int $limit): HistoryList
    {
        $data = [];
        for ($id = 1; $id <= $rows; $id++) {
            $data[] = new NotificationHistory([
                'id'            => $id,
                'event_summary' => 'Test notification ' . $id,
                'state'         => NotificationTransmissionState::SENT,
                'triggered_at'  => new DateTime('2026-01-01T00:00:00Z'),
            ]);
        }

        return (new HistoryList(new ResultSet(new ArrayIterator($data), $limit)))
            ->setPageSize($limit)
            ->setLoadMoreUrl(Url::fromPath('notifications/history', ['before' => 42]));
    }
}
