<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\BaseItemList;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Forms\ChannelForm;
use Icinga\Module\Noma\Model\Channel;
use Icinga\Module\Noma\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Noma\Widget\ItemList\ChannelList;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tab;
use Icinga\Web\Widget\Tabs;
use ipl\Html\ValidHtml;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
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
                'name' => t('Name'),
                'type' => t('Type')
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
            (new ButtonLink(
                t('Add Channel'),
                Url::fromPath('noma/channels/add'),
                'plus'
            ))->setBaseTarget('_next')
            ->addAttributes(['class' => 'add-new-component'])
        );

        $this->addContent(new ChannelList($channels));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction()
    {
        $this->addTitleTab(t('Add Channel'));
        $form = (new ChannelForm($this->db))
            ->on(ChannelForm::ON_SUCCESS, function (ChannelForm $form) {
                Notification::success(
                    sprintf(
                        t('New channel %s has successfully been added'),
                        $form->getValue('name')
                    )
                );
                $this->redirectNow(Url::fromPath('noma/channels'));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    /**
     * Add attribute 'class' => 'full-width' if the content is an instance of BaseItemList
     *
     * @param ValidHtml $content
     *
     * @return ChannelsController
     */
    protected function addContent(ValidHtml $content)
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
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
