<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\HistoryList;
use ipl\Stdlib\Filter;
use ipl\Web\Common\Controls;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Control\ViewModeSwitcher;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\DetailedItemLayout;
use ipl\Web\Layout\ItemLayout;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;

class HistoryController extends CompatController
{
    use Auth;
    use SearchControls;
    use Controls;

    public function init()
    {
        $this->assertPermission('notifications/view/history');

        parent::init();
    }

    public function indexAction(): void
    {
        $this->addTitleTab(t('Notification History'));

        $before = $this->params->shift('before');

        $notificationHistory = NotificationHistory::on(Database::get())
            ->with(['contact', 'contactgroup', 'schedule'])
            ->withColumns(['channel_label' => 'channel.available_channel_type.name']);

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $notificationHistory,
            [
                'notification_history.triggered_at desc' => t('Triggered At'),
                'notification_history.state, notification_history.triggered_at desc' => t('State')
            ]
        );

        $paginationControl = $this->createPaginationControl($notificationHistory);
        $viewModeSwitcher = $this->createViewModeSwitcher();
        $preserveParams = [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
            $viewModeSwitcher->getViewModeParam(),
        ];
        $searchBar = $this->createSearchBar($notificationHistory, $preserveParams);

        $this->handleControls($this->getServerRequest());

        if ($searchBar->hasBeenSent() && ! $searchBar->isValid()) {
            if ($searchBar->hasBeenSubmitted()) {
                $filter = QueryString::parse((string) $this->params);
            } else {
                $this->addControl($searchBar);
                $this->sendMultipartUpdate();
                return;
            }
        } else {
            $filter = $searchBar->getFilter();
        }

        $this->applyRestrictions($notificationHistory);
        $notificationHistory->filter($filter);

        // Keep newly inserted notifications out of later pages, even if their trigger times are older.
        $before ??= NotificationHistory::on(Database::get())
            ->columns('id')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->first()->id ?? 0;

        $notificationHistory->filter(Filter::lessThanOrEqual('id', $before))
            ->orderBy('id', 'DESC')
            ->peekAhead();

        // Treat zero and negative page numbers as page 1 when calculating the query limit.
        $page = max(1, $paginationControl->getCurrentPageNumber());

        // Append only the requested page on Load More. Full requests rebuild all loaded pages.
        $appendPage = $page > 1 && ! empty($this->view->compact);

        if (! $appendPage) {
            $notificationHistory->resetOffset()->limit($page * $limitControl->getLimit());
        }

        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($viewModeSwitcher);
        $this->addControl($searchBar);

        $historyList = (new HistoryList($notificationHistory->execute()))
            ->setPageSize($limitControl->getLimit())
            ->setLoadMoreUrl(
                Url::fromRequest()->onlyWith($preserveParams)->setFilter($filter)->setParam('before', $before),
            )
            ->setItemLayoutClass(match ($viewModeSwitcher->getViewMode()->getName()) {
                'minimal'  => MinimalItemLayout::class,
                'detailed' => DetailedItemLayout::class,
                default    => ItemLayout::class,
            });

        if ($appendPage) {
            $historyList->setPageNumber($page);
            $this->getDocument()->addFrom($historyList);
        } else {
            $this->addContent($historyList);
        }

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setAutorefreshInterval(10);
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(NotificationHistory::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(NotificationHistory::on(Database::get()), [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
            ViewModeSwitcher::DEFAULT_VIEW_MODE_PARAM
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }
}
