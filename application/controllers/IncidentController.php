<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Exception\MissingParameterException;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\Detail\IncidentDetail;
use Icinga\Module\Notifications\Widget\Detail\IncidentQuickActions;
use Icinga\Module\Notifications\Widget\ItemList\ExtendedIncidentHistoryListInfinite;
use Icinga\Module\Notifications\Widget\ItemList\IncidentList;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

class IncidentController extends CompatController
{
    use Auth;
    use SearchControls;

    /** @var int $id Incident identifier used by incident-dependent actions */
    protected $id;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    /**
     * @throws MissingParameterException
     */
    public function init()
    {
        if ($this->getRequest()->getActionName() !== 'complete') {
            /** @var int $id */
            $id = $this->params->shiftRequired('incident');
            $this->id = $id;
        }
    }

    public function indexAction(): void
    {
        $query = Incident::on(Database::get())
            ->with(['object', 'object.source'])
            ->filter(Filter::equal('incident.id', $this->id));

        $this->applyRestrictions($query);

        /** @var Incident $incident */
        $incident = $query->first();
        if ($incident === null) {
            $this->httpNotFound($this->translate('Incident not found'));
        }

        $this->addControl((new IncidentList($query))->setNoSubjectLink());

        $this->controls->addAttributes(['class' => 'incident-detail']);

        $contact = Contact::on(Database::get())
            ->columns('id')
            ->filter(Filter::equal('username', $this->Auth()->getUser()->getUsername()))
            ->first();

        if ($contact !== null) {
            $this->addControl(
                (new IncidentQuickActions($incident, $contact->id))
                    ->on(IncidentQuickActions::ON_SUCCESS, function () use ($incident) {
                        $this->redirectNow(Links::incident($incident->id));
                    })
                    ->handleRequest($this->getServerRequest())
            );
        }

        $this->setTitle(t('Incident'));
        $this->getTabs()->activate('incident');
        $this->addContent(new IncidentDetail($incident));
    }

    public function historyAction(): void
    {
        // prepare data
        $history = IncidentHistory::on(Database::get())
            ->with(
                [
                    'event',
                    'event.object',
                    'event.object.source',
                    'contact',
                    'rule',
                    'rule_escalation',
                    'contactgroup',
                    'schedule',
                    'channel'
                ]
            );
        $compact = $this->view->compact;

        $paginationControl = $this->createPaginationControl($history);

        // time splitter
        /** @var string $time */
        $time = $this->params->shift('time', strval(time()));

        // create and add controls
        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $history,
            [
                'incident_history.time desc' => $this->translate('Entry time')
            ]
        );
        $searchBar = $this->createSearchBar($history, [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
            'incident'
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

        // page mangement
        $page = $paginationControl->getCurrentPageNumber();
        if ($page > 1 && ! $compact) {
            $history->resetOffset();
            $history->limit($page * $limitControl->getLimit());
        }

        // restrict query and apply filters
        $this->applyRestrictions($history);
        $history->filter(Filter::equal('incident.id', $this->id));
        $history->filter($filter);
        $history->filter(Filter::lessThanOrEqual('time', $time));
        $history->peekAhead();

        // add controls
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);

        $url = Url::fromRequest()
            ->onlyWith([
                $limitControl->getLimitParam(),
                $sortControl->getSortParam(),
                'incident'
            ]);
        $url->setQueryString(QueryString::render($filter) . '&' . $url->getParams()->toString());

        // create and render history list
        $list = (new ExtendedIncidentHistoryListInfinite($history->execute()))
            ->setPageSize($limitControl->getLimit())
            ->setLoadMoreUrl($url->setParam('time', $time));

        if ($compact) {
            $list->setPageNumber($page);
        }

        if ($compact && $page > 1) {
            $this->document->addFrom($list);
        } else {
            $this->addContent($list);
        }

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setTitle($this->translate('History'));
        $this->getTabs()->activate('history');
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(IncidentHistory::class);
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

    public function getTabs()
    {
        return parent::getTabs()
            ->add('incident', [
                'label' => $this->translate('Incident'),
                'url' => Links::incident($this->id)
            ])
            ->add('history', [
                'label' => $this->translate('History'),
                'url' => Links::incidentHistory($this->id)
            ]);
    }

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    protected function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string)$this->params);
        }

        return $this->filter;
    }
}
