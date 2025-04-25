<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\View\ScheduleRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Module\Notifications\Widget\TimeGrid\DaysHeader;
use ipl\Html\Attributes;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Tabs;

class SchedulesController extends CompatController
{
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function indexAction(): void
    {
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
                t('New Schedule'),
                Links::scheduleAdd(),
                'plus',
                [
                    'class' => 'add-new-component'
                ]
            ))->openInModal()
        );

        $this->addContent(new HtmlElement(
            'div',
            Attributes::create(['class' => 'schedules-header']),
            new DaysHeader((new DateTime())->setTime(0, 0), 7)
        ));

        $this->addContent(new ObjectList($schedules, new ScheduleRenderer()));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->getTabs()->activate('schedules');
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

    public function getTabs(): Tabs
    {
        return parent::getTabs()
            ->add('schedules', [
                'label'         => $this->translate('Schedules'),
                'url'           => Links::schedules(),
                'baseTarget'    => '_main'
            ])->add('event-rules', [
                'label' => $this->translate('Event Rules'),
                'url'   => Links::eventRules(),
                'baseTarget' => '_main'
            ])->add('contacts', [
                'label' => $this->translate('Contacts'),
                'url'   => Links::contacts(),
                'baseTarget' => '_main'
            ])->add('contact-groups', [
                'label'      => $this->translate('Contact Groups'),
                'url'        => Links::contactGroups(),
                'baseTarget' => '_main'
            ]);
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
