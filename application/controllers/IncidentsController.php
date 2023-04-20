<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Noma\Model\Incident;
use Icinga\Module\Noma\Widget\ItemList\IncidentList;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;

class IncidentsController extends CompatController
{
    use Auth;
    use SearchControls;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Incidents'));

        $incidents = Incident::on(Database::get())
            ->with('object');

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $incidents,
            ['incident.started_at' => t('Opened On')]
        );

        $paginationControl = $this->createPaginationControl($incidents);
        $searchBar = $this->createSearchBar($incidents, [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
        ]);

        if ($searchBar->hasBeenSent() && ! $searchBar->isValid()) {
            if ($searchBar->hasBeenSubmitted()) {
                $filter = $this->getFilter();
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
        $this->addControl($searchBar);

        $this->addContent(new IncidentList($incidents));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
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
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }
}
