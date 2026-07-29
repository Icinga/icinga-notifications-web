<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Data\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Repository\ContactGroupRepository;
use Icinga\Module\Notifications\View\ContactRenderer;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\ButtonLink;

class ContactGroupController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('notifications/config/contacts');
    }

    public function indexAction(): void
    {
        $group = (new ContactGroupRepository(Database::get()))
            ->find((int) $this->params->getRequired('id'));
        if ($group === null) {
            $this->httpNotFound(t('Contact group not found'));
        }

        $this->controls->addAttributes(Attributes::create(['class' => 'contactgroup-detail']));

        $this->addControl(new ObjectHeader($group));

        $contacts = Contact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('contactgroup_member.contactgroup_id', $group->id),
            ));

        $this->addControl($this->createPaginationControl($contacts));
        $this->addControl($this->createLimitControl());

        $this->addContent(
            (new ButtonLink(
                Text::create(t('Edit Contact Group')),
                Links::contactGroupEdit($group->id)->with(['showCompact' => true, '_disableLayout' => 1]),
                'edit',
                ['class' => 'add-new-component']
            ))->openInModal()
        );

        $this->addContent(
            (new ObjectList($contacts, new ContactRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
        );

        $this->addTitleTab(sprintf(t('Contact Group: %s'), $group->name));
    }

    public function editAction(): void
    {
        $groupId = (int) $this->params->getRequired('id');
        $this->setTitle(t('Edit Contact Group'));

        (new ContactGroupForm(new NotificationConfigProvider()))
            ->setAction(
                (string) Links::contactGroupEdit($groupId)->with(['showCompact' => true, '_disableLayout' => 1])
            )
            ->on(Form::ON_REQUEST, function ($_, ContactGroupForm $form) use ($groupId) {
                $group = (new ContactGroupRepository(Database::get()))
                    ->find($groupId);
                if ($group === null) {
                    $this->httpNotFound(t('Contact group not found'));
                }

                $form->setContactGroup($group);

                $this->addContent($form);
            })
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if ($form->hasBeenRemoved()) {
                    $group = $form->getContactGroup();
                    Database::get()->transaction(
                        fn(Connection $db) => (new ContactGroupRepository($db))->delete($group->id)
                    );
                    Notification::success(sprintf(t('Deleted contact group "%s" successfully'), $group->name));
                    $this->switchToSingleColumnLayout();
                } elseif (! $form->hasBeenSubmitted() && ! $form->hasBeenDuplicated()) {
                    foreach ($form->getPartUpdates() as $update) {
                        if (! is_array($update)) {
                            $update = [$update];
                        }

                        $this->addPart(...$update);
                    }
                } else {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })
            ->on(Form::ON_SUBMIT, function (ContactGroupForm $form) {
                $group = $form->getContactGroup();

                if ($form->hasBeenDuplicated()) {
                    $groupId = Database::get()->transaction(
                        fn (Connection $db) => (new ContactGroupRepository($db))->create($group)
                    );
                    Notification::success(sprintf(t('Successfully duplicated contact group %s'), $group->name));
                    $this->sendExtraUpdates(['#col1']);
                    $this->redirectNow(Links::contactGroup($groupId));
                } else {
                    Database::get()->transaction(
                        fn (Connection $db) => (new ContactGroupRepository($db))->update($group)
                    );
                    Notification::success(sprintf(t('Successfully updated contact group %s'), $group->name));
                    $this->closeModalAndRefreshRemainingViews(Links::contactGroup($group->id));
                }
            })
            ->handleRequest($this->getServerRequest());
    }
}
