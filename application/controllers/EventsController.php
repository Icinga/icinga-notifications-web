<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Noma\Widget\ItemList\EventList;
use Icinga\Module\Noma\Model\Event;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

class EventsController extends CompatController
{
    use Auth;
    use SearchControls;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Events'));

        $compact = $this->view->compact;

        $events = Event::on(Database::get())
            ->with(['source', 'object', 'incident']);

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $events,
            ['event.time desc' => t('Received On')]
        );

        $paginationControl = $this->createPaginationControl($events);

        $before = $this->params->shift('before', time());
        $preserveParams = [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
        ];

        $searchBar = $this->createSearchBar($events, $preserveParams);

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

        $events->peekAhead();

        $page = $paginationControl->getCurrentPageNumber();

        if ($page > 1 && ! $compact) {
            $events->resetOffset();
            $events->limit($page * $limitControl->getLimit());
        }

        $this->applyRestrictions($events);
        $events->filter($filter);
        $events->filter(Filter::lessThanOrEqual('time', $before));

        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);

        $url = Url::fromRequest()->onlyWith($preserveParams);
        $url->setQueryString(QueryString::render($filter) . '&' . $url->getParams()->toString());

        $eventList = (new EventList($events->execute()))
            ->setPageSize($limitControl->getLimit())
            ->setLoadMoreUrl($url->setParam('before', $before));

        if ($compact) {
            $eventList->setPageNumber($page);
        }

        if ($compact && $page > 1) {
            $this->document->addFrom($eventList);
        } else {
            $this->addContent($eventList);
        }

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Event::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(Event::on(Database::get()), [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }
}
