<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Web\Notification;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class ContactController extends CompatController
{
    /** @var Connection */
    private $db;

    public function init()
    {
        $this->assertPermission('notifications/config/contacts');

        $this->db = Database::get();
    }

    public function indexAction()
    {
        $contact = Contact::on($this->db);
        $contactId = $this->params->getRequired('id');

        $contact->filter(Filter::equal('id', $contactId));

        $contact = $contact->first();

        $this->addTitleTab(sprintf(t('Contact: %s'), $contact->full_name));

        $form = (new ContactForm($this->db, $contactId))
            ->populate($contact)
            ->on(ContactForm::ON_SUCCESS, function (ContactForm $form) {
                $form->addOrUpdateContact();
                /** @var FieldsetElement $contactElement */
                $contactElement = $form->getElement('contact');
                Notification::success(sprintf(
                    t('Contact "%s" has successfully been saved'),
                    $contactElement->getValue('full_name')
                ));

                $this->redirectNow('__CLOSE__');
            })->on(ContactForm::ON_REMOVE, function (ContactForm $form) {
                $form->removeContact();
                /** @var FieldsetElement $contactElement */
                $contactElement = $form->getElement('contact');
                Notification::success(sprintf(
                    t('Deleted contact "%s" successfully'),
                    $contactElement->getValue('full_name')
                ));

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }
}
