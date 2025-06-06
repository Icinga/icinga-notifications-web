<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ChannelForm;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\View\ChannelRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tab;
use Icinga\Web\Widget\Tabs;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\ButtonLink;

class ChannelsController extends CompatController
{
    use SearchControls;

    /** @var Connection */
    private $db;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function init()
    {
        $this->assertPermission('config/modules');

        $this->db = Database::get();
    }

    public function indexAction()
    {
        $channels = Channel::on($this->db);
        $this->mergeTabs($this->Module()->getConfigTabs());
        $this->getTabs()->activate('channels');

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($channels);
        $sortControl = $this->createSortControl(
            $channels,
            [
                'name'          => t('Name'),
                'type'          => t('Type'),
                'changed_at'    => t('Changed At')
            ]
        );

        $searchBar = $this->createSearchBar(
            $channels,
            [
                $limitControl->getLimitParam(),
                $sortControl->getSortParam()
            ]
        );

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

        $channels->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);
        $this->addContent(
            (new ButtonLink(t('Add Channel'), Links::channelAdd(), 'plus'))
                ->setBaseTarget('_next')
                ->addAttributes(['class' => 'add-new-component'])
        );

        $this->addContent(
            (new ObjectList($channels, new ChannelRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction()
    {
        $this->addTitleTab(t('Add Channel'));
        $form = (new ChannelForm($this->db))
            ->on(ChannelForm::ON_SUCCESS, function (ChannelForm $form) {
                $form->addChannel();
                Notification::success(
                    sprintf(
                        t('New channel %s has successfully been added'),
                        $form->getValue('name')
                    )
                );
                $this->redirectNow(Links::channels());
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Channel::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Channel::on($this->db),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    protected function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);
        }

        return $this->filter;
    }

    /**
     * Merge tabs with other tabs contained in this tab panel
     *
     * @param Tabs $tabs
     *
     * @return void
     */
    protected function mergeTabs(Tabs $tabs): void
    {
        /** @var Tab $tab */
        foreach ($tabs->getTabs() as $tab) {
            $this->tabs->add($tab->getName(), $tab);
        }
    }
}
