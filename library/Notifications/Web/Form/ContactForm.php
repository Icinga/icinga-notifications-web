<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Form;

use Icinga\Exception\Http\HttpNotFoundException;
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

    public function __construct(Connection $db)
    {
        $this->db = $db;

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
        $channelOptions += Channel::fetchChannelNames($this->db);

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
                    $contact = Contact::on($this->db)
                        ->filter(Filter::all(
                            Filter::equal('username', $value),
                            Filter::equal('deleted', 'n')
                        ));
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

    /**
     * Load the contact with given id
     *
     * @param int $id
     *
     * @return $this
     *
     * @throws HttpNotFoundException
     */
    public function loadContact(int $id): self
    {
        $this->contactId = $id;
        $this->populate($this->fetchDbValues());

        return $this;
    }

    /**
     * Add the new contact
     */
    public function addContact(): void
    {
        $contactInfo = $this->getValues();

        $contact = $contactInfo['contact'];
        $addressFromForm = $contactInfo['contact_address'];

        $this->db->beginTransaction();

        $this->db->insert('contact', $contact);
        $this->contactId = $this->db->lastInsertId();

        foreach ($addressFromForm as $type => $value) {
            $this->insertOrUpdateAddress($type, $addressFromForm, []);
        }

        $this->db->commitTransaction();
    }

    /**
     * Edit the contact
     *
     * @return void
     */
    public function editContact(): void
    {
        $this->db->beginTransaction();

        $values = $this->getValues();
        $storedValues = $this->fetchDbValues();

        if (
            $storedValues['contact'] === $values['contact']
            && $storedValues['contact_address'] === $values['contact_address']
        ) {
            return;
        }

        $contact = $values['contact'];
        $addressFromForm = $values['contact_address'];

        $contact['changed_at'] = time() * 1000;
        $this->db->update('contact', $contact, ['id = ?' => $this->contactId]);

        $addressObjects = ContactAddress::on($this->db)
            ->filter(Filter::equal('contact_id', $this->contactId));

        $addressFromDb = [];
        foreach ($addressObjects as $addressRow) {
            $addressFromDb[$addressRow->type] = [$addressRow->id, $addressRow->address];
        }

        foreach ($addressFromForm as $type => $_) {
            $this->insertOrUpdateAddress($type, $addressFromForm, $addressFromDb);
        }

        $this->db->commitTransaction();
    }

    /**
     * Remove the contact
     */
    public function removeContact(): void
    {
        $this->db->beginTransaction();

        $markAsDeleted = ['changed_at' => time() * 1000, 'deleted' => 'y'];
        $this->db->update('contactgroup_member', $markAsDeleted, ['contact_id = ?' => $this->contactId]);
        $this->db->update('contact_address', $markAsDeleted, ['contact_id = ?' => $this->contactId]);
        $this->db->update('contact', $markAsDeleted + ['username' => null], ['id = ?' => $this->contactId]);

        $this->db->commitTransaction();
    }

    /**
     * Get the contact name
     *
     * @return string
     */
    public function getContactName(): string
    {
        return $this->getElement('contact')->getValue('full_name');
    }

    /**
     * Fetch the values from the database
     *
     * @return array
     *
     * @throws HttpNotFoundException
     */
    private function fetchDbValues(): array
    {
        /** @var ?Contact $contact */
        $contact = Contact::on($this->db)
            ->filter(Filter::all(
                Filter::equal('id', $this->contactId),
                Filter::equal('deleted', 'n')
            ))
            ->first();

        if ($contact === null) {
            throw new HttpNotFoundException(t('Contact not found'));
        }

        $values['contact'] =  [
            'full_name'          => $contact->full_name,
            'username'           => $contact->username,
            'default_channel_id' => (string) $contact->default_channel_id
        ];

        $contractAddr = $contact->contact_address
            ->filter(Filter::equal('deleted', 'n'));

        $values['contact_address'] = [];
        foreach ($contractAddr as $contactInfo) {
            $values['contact_address'][$contactInfo->type] = $contactInfo->address;
        }

        return $values;
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
        $changedAt = time() * 1000;
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
                    ['address' => $addressFromForm[$type], 'changed_at' => $changedAt, 'deleted' => 'n'],
                    [
                        'id = ?'         => $addressFromDb[$type][0],
                        'contact_id = ?' => $this->contactId
                    ]
                );
            }
        } elseif (isset($addressFromDb[$type])) {
            $this->db->update(
                'contact_address',
                ['changed_at' => $changedAt, 'deleted' => 'y'],
                ['id = ?' => $addressFromDb[$type][0]]
            );
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
