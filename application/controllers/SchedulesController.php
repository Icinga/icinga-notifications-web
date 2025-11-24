<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\ConfigurationTabs;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\View\ScheduleRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\ButtonLink;

class SchedulesController extends CompatController
{
    use ConfigurationTabs;
    use SearchControls;

    /** @var ?Filter\Rule Filter from query string parameters */
    private ?Filter\Rule $filter = null;

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
                $filter = $this->getFilter();
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
        $this->addContent(
            (new ButtonLink(
                t('Create Schedule'),
                Links::scheduleAdd(),
                'plus',
                [
                    'class' => 'add-new-component'
                ]
            ))->openInModal()
        );

        $this->addContent(new ObjectList($schedules, new ScheduleRenderer()));

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

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    private function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);
        }

        return $this->filter;
    }
}
