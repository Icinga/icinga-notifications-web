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

class IncidentsController extends CompatController
{
    use Auth;
    use SearchControls;
    use Controls;

    public function init()
    {
        $this->assertPermission('notifications/view/incidents');

        parent::init();
    }

    public function indexAction(): void
    {
        $this->getTabs()->activate('open-incidents');
        $this->listIncidents('open');
    }

    public function closedAction(): void
    {
        $this->getTabs()->activate('closed-incidents');
        $this->listIncidents('closed');
    }

    /**
     * List incidents of the given type
     *
     * @param 'open'|'closed' $stateType
     *
     * @return void
     */
    private function listIncidents(string $stateType): void
    {
        $incidents = Incident::on(Database::get())
            ->with(['object'])
            ->withColumns(['object.sources'])
            ->filter(match ($stateType) {
                'open' => Filter::unlike('incident.recovered_at', '*'),
                'closed' => Filter::like('incident.recovered_at', '*')
            });

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $incidents,
            [
                'incident.severity desc, incident.started_at' => t('Severity'),
                'incident.started_at desc'                    => t('Opened On'),
                'incident.recovered_at'                       => t('Recovered At'),
            ],
            match ($stateType) {
                'open' => null,
                'closed' => 'incident.recovered_at desc'
            }
        );

        $paginationControl = $this->createPaginationControl($incidents);
        $viewModeSwitcher = $this->createViewModeSwitcher();
        $searchBar = $this->createSearchBar($incidents, [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
            $viewModeSwitcher->getViewModeParam()
        ]);
        $searchBar->getEditorUrl()->addParams(['stateType' => $stateType]);
        $searchBar->getSuggestionUrl()->addParams(['stateType' => $stateType]);

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
        $suggestions->setBaseFilter(match ($this->params->shiftRequired('stateType')) {
            'open' => Filter::unlike('incident.recovered_at', '*'),
            'closed' => Filter::like('incident.recovered_at', '*')
        });
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $stateType = $this->params->shiftRequired('stateType');

        $query = Incident::on(Database::get());
        $query->filter(match ($stateType) {
            'open' => Filter::unlike('incident.recovered_at', '*'),
            'closed' => Filter::like('incident.recovered_at', '*')
        });

        $editor = $this->createSearchEditor($query, [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
            ViewModeSwitcher::DEFAULT_VIEW_MODE_PARAM
        ]);
        $editor->getSuggestionUrl()->addParams(['stateType' => $stateType]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }

    public function getTabs()
    {
        $tabs = parent::getTabs();
        $tabs->add('open-incidents', [
            'label' => $this->translate('Open Incidents'),
            'url' => 'notifications/incidents',
            'baseTarget' => '_main'
        ]);
        $tabs->add('closed-incidents', [
            'label' => $this->translate('Closed Incidents'),
            'url' => 'notifications/incidents/closed',
            'baseTarget' => '_main'
        ]);

        return $tabs;
    }
}
