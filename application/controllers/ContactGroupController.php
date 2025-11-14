<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\View\ContactRenderer;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Text;
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
        $groupId = $this->params->getRequired('id');

        $query = Contactgroup::on(Database::get())
            ->columns(['id', 'name'])
            ->filter(Filter::equal('id', $groupId));

        $group = $query->first();
        if ($group === null) {
            $this->httpNotFound(t('Contact group not found'));
        }

        $this->controls->addAttributes(Attributes::create(['class' => 'contactgroup-detail']));

        $this->addControl(new ObjectHeader($group));

        $contacts = Contact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('contactgroup_member.contactgroup_id', $groupId),
                Filter::equal('contactgroup_member.deleted', 'n')
            ));

        $this->addControl($this->createPaginationControl($contacts));
        $this->addControl($this->createLimitControl());

        $this->addContent(
            (new ButtonLink(
                Text::create(t('Edit Contact Group')),
                Links::contactGroupEdit($groupId)->with(['showCompact' => true, '_disableLayout' => 1]),
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
        $groupId = $this->params->getRequired('id');

        $form = (new ContactGroupForm(Database::get()))
            ->loadContactgroup($groupId)
            ->setAction(
                (string) Links::contactGroupEdit($groupId)->with(['showCompact' => true, '_disableLayout' => 1])
            )
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if ($form->hasBeenRemoved()) {
                    $form->removeContactgroup();
                    Notification::success(sprintf(
                        t('Deleted contact group "%s" successfully'),
                        $form->getValue('group_name')
                    ));
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
            ->on(Form::ON_SUBMIT, function (ContactGroupForm $form) use ($groupId) {
                $form->editGroup();
                Notification::success(sprintf(
                    t('Successfully updated contact group %s'),
                    $form->getValue('group_name')
                ));

                $this->closeModalAndRefreshRemainingViews(Links::contactGroup($groupId));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
        $this->setTitle(t('Edit Contact Group'));
    }
}
