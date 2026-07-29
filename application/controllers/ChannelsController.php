<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ChannelForm;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Repository\ChannelRepository;
use Icinga\Module\Notifications\View\ChannelRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tabs;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
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

    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $channels = Channel::on(Database::get());
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
                $filter = QueryString::parse((string) $this->params);
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

        $addButton = (new ButtonLink(
            t('Add Channel'),
            Links::channelAdd(),
            'plus',
            ['class' => 'add-new-component']
        ))->setBaseTarget('_next');

        $emptyStateMessage = null;
        if (AvailableChannelType::on(Database::get())->columns([new Expression('1')])->first() === null) {
            $emptyStateMessage = t('No channel types available. Make sure Icinga Notifications is running.');
            $addButton->disable($emptyStateMessage);
        }

        $this->addContent($addButton);
        $this->addContent(
            (new ObjectList($channels, new ChannelRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction(): void
    {
        (new ChannelForm(Database::get()))
            ->on(Form::ON_REQUEST, function ($_, ChannelForm $form) {
                $this->addTitleTab(t('Add Channel'));
                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function (ChannelForm $form) {
                $channel = $form->getChannel();
                Database::get()->transaction(fn(Connection $db) => (new ChannelRepository($db))->create($channel));
                Notification::success(
                    sprintf(
                        t('New channel %s has successfully been added'),
                        $channel->name
                    )
                );
                $this->switchToSingleColumnLayout();
            })->on(Form::ON_SENT, function (ChannelForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                if (! $this->getResponse()->isRedirect()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })->handleRequest($this->getServerRequest());
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
            Channel::on(Database::get()),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
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
        foreach ($tabs->getTabs() as $tab) {
            $this->tabs->add($tab->getName(), $tab);
        }
    }
}
