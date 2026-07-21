<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use ipl\Web\Common\Controls;
use ipl\Web\Compat\SearchControls;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\View\NotificationHistoryRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\Detail\IncidentDetail;
use Icinga\Module\Notifications\Widget\Detail\IncidentQuickActions;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\DetailedItemLayout;
use ipl\Web\Layout\ItemLayout;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;

class IncidentController extends CompatController
{
    use Auth;
    use SearchControls;
    use Controls;

    public function indexAction(): void
    {
        $this->setTitle(t('Incident'));
        $this->setTitleTab($this->getRequest()->getActionName());

        $id = $this->params->getRequired('id');

        $query = Incident::on(Database::get())
            ->with(['object', 'object.source'])
            ->withColumns('object.id_tags')
            ->filter(Filter::equal('incident.id', $id));

        $this->applyRestrictions($query);

        /** @var Incident $incident */
        $incident = $query->first();
        if ($incident === null) {
            $this->httpNotFound(t('Incident not found'));
        }

        $this->addControl(new ObjectHeader($incident));

        $this->controls->addAttributes(Attributes::create(['class' => 'incident-detail']));

        /** @var ?Contact $contact */
        $contact = Contact::on(Database::get())
            ->columns('id')
            ->filter(Filter::equal('username', $this->Auth()->getUser()->getUsername()))
            ->first();

        if ($contact !== null) {
            $this->addControl(
                (new IncidentQuickActions($incident, $contact->id))
                    ->on(Form::ON_SUBMIT, function () use ($incident) {
                        $this->redirectNow(Links::incident($incident->id));
                    })
                    ->handleRequest($this->getServerRequest())
            );
        }

        $this->addContent(new IncidentDetail($incident));
    }

    public function notificationHistoryAction(): void
    {
        $this->setTitle(t('Notification History'));
        $this->setTitleTab($this->getRequest()->getActionName());

        $notificationHistory = NotificationHistory::on(Database::get())
            ->with(['channel', 'contact', 'contactgroup', 'schedule'])
            ->filter(Filter::equal('notification_history.incident_id', $this->params->shiftRequired('id')));
        $this->applyRestrictions($notificationHistory);

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $notificationHistory,
            [
                'notification_history.triggered_at desc' => t('Triggered At'),
                'notification_history.state, notification_history.triggered_at desc' => t('State')
            ]
        );

        $paginationControl = $this->createPaginationControl($notificationHistory);
        $viewModeSwitcher = $this->createViewModeSwitcher($this->params);

        $searchBar = $this->createSearchBar(
            $notificationHistory,
            [
                $limitControl->getLimitParam(),
                $sortControl->getSortParam(),
                $viewModeSwitcher->getViewModeParam(),
                'id'
            ]
        );

        $this->applyViewModeLimit($limitControl, $paginationControl);
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

        $notificationHistory->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($viewModeSwitcher);
        $this->addControl($searchBar);

        $this->addContent(
            (new ObjectList($notificationHistory, new NotificationHistoryRenderer()))
                ->setItemLayoutClass(match ($viewModeSwitcher->getViewMode()) {
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
        $suggestions = (new ObjectSuggestions())
            ->setModel(NotificationHistory::class)
            ->setBaseFilter(
                Filter::equal('notification_history.incident_id', $this->params->getRequired('id'))
            )
            ->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($suggestions);
    }

    public function searchEditorAction(): void
    {
        $preserveParams = [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
            'id'
        ];

        $editor = $this->createSearchEditor(
            NotificationHistory::on(Database::get()),
            Url::fromPath('notifications/incident/notification-history', ['id' => $this->params->getRequired('id')]),
            $preserveParams
        );
        $editor->setSuggestionUrl(
            Url::fromPath('notifications/incident/complete')
                ->setParams(Url::fromRequest()->onlyWith($preserveParams)->getParams())
        );

        $this->getDocument()->addHtml($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    protected function setTitleTab(string $name): void
    {
        $id = $this->params->getRequired('id');
        $this->getTabs()
            ->add('index', [
                'label'  => $this->translate('Incident'),
                'url'    => Links::incident($id)
            ])
            ->add('notification-history', [
                'label'  => $this->translate('Notification History'),
                'url'    => Url::fromPath('notifications/incident/notification-history', ['id' => $id])
            ])
            ->activate($name);
    }
}
