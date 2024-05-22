<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Widget\ItemList\ContactList;
use Icinga\Web\Notification;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseItemList;
use ipl\Web\Compat\CompatController;
use ipl\Web\Widget\ButtonLink;

class ContactGroupController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('notifications/config/contact-groups');
    }

    public function indexAction(): void
    {
        $groupId = $this->params->getRequired('id');

        $query = Contactgroup::on(Database::get())
            ->columns(['id', 'name'])
            ->filter(Filter::equal('id', $groupId));

        $group = $query->first();
        if ($group === null) {
            $this->httpNotFound(t('Contact group not found'));
        }

        $this->controls->addAttributes(['class' => 'contactgroup-detail']);

        $this->addControl(new HtmlElement('div', new Attributes(['class' => 'header']), Text::create($group->name)));

        $this->addControl($this->createPaginationControl($group->contact));
        $this->addControl($this->createLimitControl());

        $this->addContent(
            (new ButtonLink(
                Text::create(t('Edit Contact Group')),
                Links::contactGroupEdit($groupId),
                'edit',
                ['class' => 'add-new-component']
            ))->openInModal()
        );

        $this->addContent(new ContactList($group->contact));

        $this->addTitleTab(t('Contact Group'));
        $this->setTitle(sprintf(t('Contact Group: %s'), $group->name));
    }

    public function editAction(): void
    {
        $groupId = $this->params->getRequired('id');

        $form = (new ContactGroupForm(Database::get()))
            ->loadContactgroup($groupId)
            ->setAction($this->getRequest()->getUrl()->getAbsoluteUrl())
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if ($form->hasBeenRemoved()) {
                    $form->removeContactgroup();
                    Notification::success(sprintf(
                        t('Successfully removed contact group %s'),
                        $form->getValue('group_name')
                    ));
                    $this->switchToSingleColumnLayout();
                } elseif ($form->hasBeenCancelled()) {
                    $this->closeModalAndRefreshRelatedView($this->getRequest()->getUrl()->getAbsoluteUrl());
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
                if ($form->editGroup()) {
                    Notification::success(sprintf(
                        t('Successfully updated contact group %s'),
                        $form->getValue('group_name')
                    ));
                }

                $this->closeModalAndRefreshRemainingViews($this->getRequest()->getUrl()->getAbsoluteUrl());
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
        $this->setTitle(t('Edit Contact Group'));
    }

    protected function addContent(ValidHtml $content): self
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
    }
}
