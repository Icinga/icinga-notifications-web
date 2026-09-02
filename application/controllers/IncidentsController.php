<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\IncidentRenderer;
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

class IncidentsController extends CompatController
{
    use Auth;
    use SearchControls;
    use Controls;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Incidents'));

        $incidents = Incident::on(Database::get())
            ->with(['object'])
            ->withColumns(['object.sources']);

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $incidents,
            [
                'incident.severity desc, incident.started_at' => t('Severity'),
                'incident.started_at desc'                    => t('Opened On'),
                'incident.recovered_at'                       => t('Recovered At'),
            ]
        );

        $paginationControl = $this->createPaginationControl($incidents);
        $viewModeSwitcher = $this->createViewModeSwitcher();
        $searchBar = $this->createSearchBar($incidents, [
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

        $this->applyRestrictions($incidents);
        $incidents->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($viewModeSwitcher);
        $this->addControl($searchBar);

        $this->addContent(
            (new ObjectList($incidents, new IncidentRenderer()))
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
        $suggestions->setModel(Incident::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(Incident::on(Database::get()), [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
            ViewModeSwitcher::DEFAULT_VIEW_MODE_PARAM
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }
}
