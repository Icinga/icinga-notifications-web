<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\ConfigurationTabs;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\View\ContactgroupRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Module\Notifications\Widget\MemberSuggestions;
use Icinga\Web\Notification;
use ipl\Html\Form;
use ipl\Html\HtmlString;
use ipl\Html\TemplateString;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\ActionLink;
use ipl\Web\Widget\ButtonLink;

class ContactGroupsController extends CompatController
{
    use ConfigurationTabs;
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function init(): void
    {
        $this->assertPermission('notifications/config/contacts');
    }

    public function indexAction(): void
    {
        $this->setTitle($this->translate('Contact Groups'));
        $this->getTabs()->activate('contact-groups');

        $groups = Contactgroup::on(Database::get());

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($groups);
        $sortControl = $this->createSortControl(
            $groups,
            [
                'name'          => $this->translate('Group Name'),
                'changed_at'    => $this->translate('Changed At')
            ]
        );

        $searchBar = $this->createSearchBar(
            $groups,
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

        $groups->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);

        $addButton = new ButtonLink(
            $this->translate('Create Contact Group'),
            Links::contactGroupsAdd()->with(['showCompact' => true, '_disableLayout' => 1]),
            'plus',
            ['class' => 'add-new-component']
        );

        $emptyStateMessage = null;
        if (Contact::on(Database::get())->columns([new Expression('1')])->limit(1)->first() === null) {
            if (Channel::on(Database::get())->columns([new Expression('1')])->limit(1)->first() === null) {
                $addButton->disable($this->translate('A channel is required to add a contact group'));

                if ($this->Auth()->hasPermission('config/modules')) {
                    $emptyStateMessage = TemplateString::create(
                        // translators: %1$s will be replaced by a line break
                        $this->translate(
                            'No contact groups found.%1$s'
                            . 'To add new contact group, please {{#link}}configure a Channel{{/link}} first.%1$s'
                            . 'Once done, you should proceed by creating your first contact.'
                        ),
                        ['link' => (new ActionLink(null, Links::channelAdd()))->setBaseTarget('_next')],
                        [HtmlString::create('<br>')]
                    );
                } else {
                    $emptyStateMessage = $this->translate(
                        'No contact groups found. To add a new contact group, a channel is required.'
                        . ' Please contact your system administrator.'
                    );
                }
            } else {
                $emptyStateMessage = TemplateString::create(
                    $this->translate(
                        'No contact groups found. Do not forget to also'
                        . ' {{#link}}create your first contact!{{/link}}'
                    ),
                    ['link' => (new ActionLink(null, Links::contactAdd()))->setBaseTarget('_next')]
                );

                $addButton->openInModal();
            }
        } else {
            $addButton->openInModal();
        }

        $this->addContent($addButton);

        $this->addContent(
            (new ObjectList($groups, new ContactgroupRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction(): void
    {
        $form = (new ContactGroupForm(Database::get()))
            ->setAction((string) Links::contactGroupsAdd()->with(['showCompact' => true, '_disableLayout' => 1]))
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if (! $form->hasBeenSubmitted()) {
                    foreach ($form->getPartUpdates() as $update) {
                        if (! is_array($update)) {
                            $update = [$update];
                        }

                        $this->addPart(...$update);
                    }
                }
            })
            ->on(Form::ON_SUCCESS, function (ContactGroupForm $form) {
                $groupIdentifier = $form->addGroup();

                Notification::success($this->translate('New contact group has been successfully added'));
                $this->sendExtraUpdates(['#col1']);
                $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                $this->redirectNow(Links::contactGroup($groupIdentifier));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
        $this->setTitle($this->translate('Create Contact Group'));
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Contactgroup::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Contactgroup::on(Database::get()),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    public function suggestMemberAction(): void
    {
        $members = new MemberSuggestions();
        $members->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($members);
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
