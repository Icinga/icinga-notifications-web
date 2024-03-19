<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Web\Form;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use Icinga\Web\Session;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class ContactForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string Emitted in case the contact should be deleted */
    public const ON_REMOVE = 'on_remove';

    /** @var Connection */
    private $db;

    /** @var ?string Contact ID*/
    private $contactId;

    public function __construct(Connection $db, $contactId = null)
    {
        $this->db = $db;
        $this->contactId = $contactId;

        $this->on(self::ON_SENT, function () {
            if ($this->hasBeenRemoved()) {
                $this->emit(self::ON_REMOVE, [$this]);
            }
        });
    }

    /**
     * Get whether the user pushed the remove button
     *
     * @return bool
     */
    private function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    public function isValidEvent($event)
    {
        if ($event === self::ON_REMOVE) {
            return true;
        }

        return parent::isValidEvent($event);
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

        $channelOptions = ['' => sprintf(' - %s - ', $this->translate('Please choose'))];
        $channelOptions += Channel::fetchChannelNames(Database::get());

        $contact->addElement(
            'text',
            'full_name',
            [
                'label' => $this->translate('Full Name'),
                'required' => true
            ]
        )->addElement(
            'text',
            'username',
            [
                'label' => $this->translate('Username'),
                'validators' => [new CallbackValidator(function ($value, $validator) {
                    $contact = Contact::on($this->db)->filter(Filter::equal('username', $value));
                    if ($this->contactId) {
                        $contact->filter(Filter::unequal('id', $this->contactId));
                    }

                    if ($contact->first() !== null) {
                        $validator->addMessage($this->translate('A contact with the same username already exists.'));

                        return false;
                    }

                    return true;
                })]
            ]
        )->addElement(
            'color',
            'color',
            [
                'label' => $this->translate('Color'),
                'required' => true
            ]
        )->addElement(
            'select',
            'default_channel_id',
            [
                'label'             => $this->translate('Default Channel'),
                'required'          => true,
                'disabledOptions'   => [''],
                'options'           => $channelOptions
            ]
        );

        $this->addAddressElements();

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $this->contactId === null ?
                    $this->translate('Add Contact') :
                    $this->translate('Save Changes')
            ]
        );
        if ($this->contactId !== null) {
            /** @var FormSubmitElement $deleteButton */
            $deleteButton = $this->createElement(
                'submit',
                'delete',
                [
                    'label'          => $this->translate('Delete'),
                    'class'          => 'btn-remove',
                    'formnovalidate' => true
                ]
            );

            $this->registerElement($deleteButton);
            $this->getElement('submit')
                ->getWrapper()
                ->prepend($deleteButton);
        }
    }

    public function populate($values)
    {
        if ($values instanceof Contact) {
            $formValues = [];
            if (! isset($formValues['contact'])) {
                $formValues['contact'] = [
                    'full_name'          => $values->full_name,
                    'username'           => $values->username,
                    'color'              => $values->color,
                    'default_channel_id' => $values->default_channel_id
                ];
            }

            foreach ($values->contact_address as $contactInfo) {
                if (! isset($formValues['contact_address'])) {
                    $formValues['contact_address'] = [
                        'email'       => null,
                        'rocketchat' => null
                    ];
                }

                if ($contactInfo->type === 'email') {
                    $formValues['contact_address']['email' ] = $contactInfo->address;
                }

                if ($contactInfo->type === 'rocketchat') {
                    $formValues['contact_address']['rocketchat'] = $contactInfo->address;
                }
            }

            $values = $formValues;
        }

        parent::populate($values);

        return $this;
    }

    public function addOrUpdateContact()
    {
        $contactInfo = $this->getValues();

        $contact = $contactInfo['contact'];
        $addressFromForm = $contactInfo['contact_address'];

        $this->db->beginTransaction();

        $addressFromDb = [];
        if ($this->contactId === null) {
            $this->db->insert('contact', $contact);

            $this->contactId = $this->db->lastInsertId();
        } else {
            $this->db->update('contact', $contact, ['id = ?' => $this->contactId]);

            $addressObjects = ContactAddress::on($this->db);

            $addressObjects->filter(Filter::equal('contact_id', $this->contactId));

            foreach ($addressObjects as $addressRow) {
                    $addressFromDb[$addressRow->type] = [$addressRow->id, $addressRow->address];
            }
        }

        $addr = ! empty($addressFromDb) ? $addressFromDb : $addressFromForm;
        foreach ($addr as $type => $value) {
            $this->insertOrUpdateAddress($type, $addressFromForm, $addressFromDb);
        }

        $this->db->commitTransaction();
    }

    public function removeContact()
    {
        $this->db->beginTransaction();
        $this->db->delete('contact_address', ['contact_id = ?' => $this->contactId]);
        $this->db->delete('contact', ['id = ?' => $this->contactId]);
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
            if (! isset($addressFromDb[$type])) {
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
        } elseif (isset($addressFromDb[$type])) {
            $this->db->delete('contact_address', ['id = ?' => $addressFromDb[$type][0]]);
        }
    }

    /**
     * Add address elements for all existing channel plugins
     *
     * @return void
     */
    private function addAddressElements(): void
    {
        $plugins = $this->db->fetchPairs(
            AvailableChannelType::on($this->db)
                ->columns(['type', 'name'])
                ->assembleSelect()
        );

        if (empty($plugins)) {
            return;
        }

        $address = new FieldsetElement('contact_address', ['label' => $this->translate('Addresses')]);
        $this->addElement($address);

        foreach ($plugins as $type => $label) {
            $element = $this->createElement('text', $type, ['label' => $label]);
            if ($type === 'email') {
                $element->addAttributes(['validators' => [new EmailAddressValidator()]]);
            }

            $address->addElement($element);
        }
    }
}
