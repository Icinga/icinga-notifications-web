<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Web\Notification;
use ipl\Web\Compat\CompatController;

class ContactController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('notifications/config/contacts');
    }

    public function indexAction(): void
    {
        $contactId = $this->params->getRequired('id');

        $form = (new ContactForm(Database::get()))
            ->loadContact($contactId)
            ->on(ContactForm::ON_SUCCESS, function (ContactForm $form) {
                $form->editContact();
                Notification::success(sprintf(
                    t('Contact "%s" has successfully been saved'),
                    $form->getContactName()
                ));

                $this->redirectNow('__CLOSE__');
            })->on(ContactForm::ON_REMOVE, function (ContactForm $form) {
                $form->removeContact();
                Notification::success(sprintf(
                    t('Deleted contact "%s" successfully'),
                    $form->getContactName()
                ));

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf(t('Contact: %s'), $form->getContactName()));

        $this->addContent($form);
    }
}
