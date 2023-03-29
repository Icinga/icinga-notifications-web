<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Contact;
use Icinga\Module\Noma\Web\Form\ContactForm;
use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class ContactController extends CompatController
{
    /** @var Connection */
    private $db;

    public function init()
    {
        $this->assertPermission('noma/config/contacts');

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
                Notification::success(sprintf(
                    t('Contact "%s" has successfully been saved'),
                    $form->getElement('contact')->getValue('full_name')
                ));

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }
}
