<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Web\Form;

use Icinga\Module\Noma\Model\Contact;
use Icinga\Module\Noma\Model\ContactAddress;
use Icinga\Web\Session;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class ContactForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var Connection */
    private $db;

    /** @var ?string Contact ID*/
    private $contactId;

    public function __construct(Connection $db, $contactId = null)
    {
        $this->db = $db;
        $this->contactId = $contactId;
    }

    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        // Fieldset for contact full name and username
        $contact = (new FieldsetElement(
            'contact',
            [
                'label' => $this->translate('Contact'),
            ]
        ));

        $this->addElement($contact);

        $contact->addElement(
            'text',
            'full_name',
            [
                'label'    => $this->translate('Full Name'),
                'required' => true
            ]
        )->addElement(
            'text',
            'username',
            [
                'label'    => $this->translate('Username'),
                'required' => true
            ]
        );

        // Fieldset for addresses
        $address = (new FieldsetElement(
            'contact_address',
            [
                'label'    => $this->translate('Addresses'),
            ]
        ));

        $this->addElement($address);

        $address->addElement(
            'text',
            'email',
            [
                'label'      => $this->translate('Email Address'),
                'validators' => [new EmailAddressValidator()]
            ]
        )->addElement(
            'text',
            'rocket.chat',
            [
                'label' => $this->translate('Rocket.Chat Username'),
            ]
        );

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $this->contactId === null ?
                    $this->translate('Add Contact') :
                    $this->translate('Save Changes')
            ]
        );
    }

    public function populate($values)
    {
        if ($values instanceof Contact) {
            $formValues = [];
            if (! isset($formValues['contact'])) {
                $formValues['contact'] = [
                    'full_name' => $values->full_name,
                    'username'  => $values->username,
                ];
            }

            foreach ($values->contact_address as $contactInfo) {
                if (! isset($formValues['contact_address'])) {
                    $formValues['contact_address'] = [
                        'email'       => null,
                        'rocket.chat' => null
                    ];
                }

                if ($contactInfo->type === 'email') {
                    $formValues['contact_address']['email' ] = $contactInfo->address;
                }

                if ($contactInfo->type === 'rocket.chat') {
                    $formValues['contact_address']['rocket.chat'] = $contactInfo->address;
                }
            }

            $values = $formValues;
        }

        parent::populate($values);

        return $this;
    }

    protected function onSuccess()
    {
        $contactInfo = $this->getValues();

        $contact = $contactInfo['contact'];
        $addressFromForm = $contactInfo['contact_address'];

        $this->db->beginTransaction();

        $addressFromDb = [
            'email'       => null,
            'rocket.chat' => null
        ];

        if ($this->contactId === null) {
            $this->db->insert('contact', $contact);

            $this->contactId = $this->db->lastInsertId();
        } else {
            $this->db->update('contact', $contact, ['id = ?' => $this->contactId]);

            $addressObjects = ContactAddress::on($this->db);

            $addressObjects->filter(Filter::equal('contact_id', $this->contactId));

            foreach ($addressObjects as $addressRow) {
                if ($addressRow->type === 'email') {
                    $addressFromDb['email'] = [$addressRow->id, $addressRow->address];
                }

                if ($addressRow->type === 'rocket.chat') {
                    $addressFromDb['rocket.chat'] = [$addressRow->id, $addressRow->address];
                }
            }
        }

        foreach ($addressFromDb as $type => $value) {
            $this->insertOrUpdateAddress($type, $addressFromForm, $addressFromDb);
        }

        $this->db->commitTransaction();
    }

    /**
     * Insert / Update contact address for a given contact
     *
     * @param string $type
     * @param array $addressFromForm
     * @param array $addressFromDb [id, address] from `contact_adrress` table
     *
     * @return void
     */
    private function insertOrUpdateAddress(string $type, array $addressFromForm, array $addressFromDb): void
    {
        if ($addressFromForm[$type] !== null) {
            if ($addressFromDb[$type] === null) {
                $address = [
                    'contact_id' => $this->contactId,
                    'type'       => $type,
                    'address'    => $addressFromForm[$type]
                ];

                $this->db->insert('contact_address', $address);
            } elseif ($addressFromDb[$type][1] !== $addressFromForm[$type]) {
                $this->db->update(
                    'contact_address',
                    ['address' => $addressFromForm[$type]],
                    [
                        'id = ?'         => $addressFromDb[$type][0],
                        'contact_id = ?' => $this->contactId
                    ]
                );
            }
        } elseif ($addressFromDb[$type] !== null) {
            $this->db->delete('contact_address', ['id = ?' => $addressFromDb[$type][0]]);
        }
    }
}
