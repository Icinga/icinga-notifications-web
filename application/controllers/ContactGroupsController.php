<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Widget\ItemList\ContactGroupList;
use Icinga\Module\Notifications\Widget\MemberSuggestions;
use Icinga\Web\Notification;
use ipl\Html\Form;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Web\Common\BaseItemList;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Tabs;

class ContactGroupsController extends CompatController
{
    use SearchControls;

    public function init(): void
    {
        $this->assertPermission('notifications/config/contact-groups');
    }

    public function indexAction(): void
    {
        $groups = Contactgroup::on(Database::get());

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($groups);
        $sortControl = $this->createSortControl(
            $groups,
            [
                'name' => t('Group Name'),
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
                // TODO(nc): Add proper filter
                // $filter = $this->getFilter();
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
        $this->addContent(
            (new ButtonLink(
                Text::create(t('Add Contact Group')),
                Links::contactGroupsAdd(),
                'plus',
                ['class' => 'add-new-component']
            ))->openInModal()
        );

        $this->addContent(new ContactGroupList($groups));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setTitle(t('Contact Groups'));
        $this->getTabs()->activate('contact-groups');
    }

    public function addAction(): void
    {
        $form = (new ContactGroupForm(Database::get()))
            ->setAction($this->getRequest()->getUrl()->getAbsoluteUrl())
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if ($form->hasBeenCancelled()) {
                    $this->switchToSingleColumnLayout();
                } elseif (! $form->hasBeenSubmitted()) {
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
                if ($groupIdentifier) {
                    Notification::success(t('New group has been successfully added'));
                } else {
                    Notification::error(t('Failed to add new contact group'));
                }

                $this->closeModalAndRefreshRemainingViews($this->getRequest()->getUrl()->getAbsoluteUrl());
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
        $this->setTitle(t('Add Contact Group'));
    }

    public function suggestMemberAction(): void
    {
        $members = new MemberSuggestions();
        $members->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($members);
    }

    public function getTabs(): Tabs
    {
        if ($this->getRequest()->getActionName() === 'index') {
            return parent::getTabs()
                ->add('schedules', [
                    'label'      => t('Schedules'),
                    'url'        => Links::schedules(),
                    'baseTarget' => '_main'
                ])->add('event-rules', [
                    'label'      => t('Event Rules'),
                    'url'        => Links::eventRules(),
                    'baseTarget' => '_main'
                ])->add('contacts', [
                    'label'      => t('Contacts'),
                    'url'        => Links::contacts(),
                    'baseTarget' => '_main'
                ])->add('contact-groups', [
                    'label'      => t('Contact Groups'),
                    'url'        => Links::contactGroups(),
                    'baseTarget' => '_main'
                ]);
        }

        return parent::getTabs();
    }

    protected function addContent(ValidHtml $content): self
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
    }
}
