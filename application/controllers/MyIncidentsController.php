<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\IncidentRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\ItemList;
use ipl\Web\Widget\ListItem;
use ipl\Web\Widget\Tabs;

class MyIncidentsController extends CompatController
{
    use Auth;
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function indexAction(): void
    {
        $this->getTabs()->activate('my-incidents');

        $incidents = Incident::on(Database::get())
            ->with(['object', 'object.source'])
            ->withColumns('object.id_tags')
            ->filter(Filter::equal('contact.username', $this->Auth()->getUser()->getUsername()));

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
        $searchBar = $this->createSearchBar($incidents, [
            $limitControl->getLimitParam(),
            $sortControl->getSortParam(),
        ]);

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
        $this->addControl($searchBar);

        $incidentList = (new ObjectList($incidents, new IncidentRenderer()))
            ->setItemLayoutClass(MinimalItemLayout::class)
            ->on(ItemList::ON_ITEM_ADD, function (ListItem $item, Incident $data) {
                ObjectsRendererHook::register($data->object);
            })
            ->on(ItemList::ON_ASSEMBLED, function () {
                ObjectsRendererHook::load();
            });

        $this->addContent($incidentList);

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
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }

    protected function getPageSize($default)
    {
        return parent::getPageSize($default ?? 50);
    }

    public function getTabs(): Tabs
    {
        $tabs = parent::getTabs();

        $tabs->add('incidents', [
            'label' => t('Incidents'),
            'url' => Links::incidents(),
            'baseTarget' => '_main'
        ]);

        $contact = Contact::on(Database::get())
            ->columns([new Expression('1')])
            ->filter(Filter::equal('username', $this->Auth()->getUser()->getUsername()))
            ->first();
        if ($contact !== null) {
            $tabs->add('my-incidents', [
                'label' => t('My Incidents'),
                'url' => $this->getRequest()->getUrl(),
                'baseTarget' => '_main'
            ]);
        }

        return $tabs;
    }
}
