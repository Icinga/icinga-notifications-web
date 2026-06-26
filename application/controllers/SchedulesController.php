<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\ConfigurationTabs;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\View\ScheduleRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\HtmlString;
use ipl\Html\TemplateString;
use ipl\Sql\Expression;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\ActionLink;
use ipl\Web\Widget\ButtonLink;

class SchedulesController extends CompatController
{
    use ConfigurationTabs;
    use SearchControls;

    public function init(): void
    {
        $this->assertPermission('notifications/config/schedules');
    }

    public function indexAction(): void
    {
        $this->setTitle(t('Schedules'));
        $this->getTabs()->activate('schedules');

        $schedules = Schedule::on(Database::get());

        $limitControl = $this->createLimitControl();
        $sortControl = $this->createSortControl(
            $schedules,
            [
                'schedule.name' => t('Name'),
                'changed_at'    => t('Changed At')
            ]
        );

        $paginationControl = $this->createPaginationControl($schedules);
        $searchBar = $this->createSearchBar($schedules, [
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

        $schedules->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);

        $addButton = new ButtonLink(
            $this->translate('Create Schedule'),
            Links::scheduleAdd(),
            'plus',
            [
                'class' => 'add-new-component'
            ]
        );
        $this->addContent($addButton);

        $emptyStateMessage = null;
        if (Contact::on(Database::get())->columns([new Expression('1')])->limit(1)->first() === null) {
            $addButton->disable($this->translate('A contact is required to add a schedule'));
            $emptyStateMessage = TemplateString::create(
                $this->translate(
                    'No schedules found.%1$s'
                    . 'To add a new schedule, please {{#link}}create a Contact{{/link}} first.'
                ),
                ['link' => (new ActionLink(null, Links::contactAdd()))->setBaseTarget('_next')],
                [HtmlString::create('<br>')]
            );
        } else {
            $addButton->openInModal();
        }

        $this->addContent(
            (new ObjectList($schedules, new ScheduleRenderer()))->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Schedule::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(Schedule::on(Database::get()), [
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }
}
