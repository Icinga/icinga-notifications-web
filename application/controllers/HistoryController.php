<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\View\NotificationRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
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

class HistoryController extends CompatController
{
    use Auth;
    use SearchControls;
    use Controls;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Notification History'));

        $notificationHistory = NotificationHistory::on(Database::get())
            ->with(['channel.available_channel_type', 'contact', 'contactgroup', 'schedule']);

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
        $searchBar = $this->createSearchBar($notificationHistory, [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
            $viewModeSwitcher->getViewModeParam()
        ]);

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

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($viewModeSwitcher);
        $this->addControl($searchBar);

        $this->addContent(
            (new ObjectList($notificationHistory, new NotificationRenderer()))
                ->setItemLayoutClass(match ($viewModeSwitcher->getViewMode()->getName()) {
                    'minimal' => MinimalItemLayout::class,
                    'detailed' => DetailedItemLayout::class,
                    'common' => ItemLayout::class
                })
        );

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
