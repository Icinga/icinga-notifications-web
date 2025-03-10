<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Form;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Web\Session;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\EmailAddressValidator;
use ipl\Validator\StringLengthValidator;
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
                'validators' => [
                    new StringLengthValidator(['max' => 254]),
                    new CallbackValidator(function ($value, $validator) {
                        $contact = Contact::on($this->db)
                            ->filter(Filter::equal('username', $value));
                        if ($this->contactId) {
                            $contact->filter(Filter::unequal('id', $this->contactId));
                        }

                        if ($contact->first() !== null) {
                            $validator->addMessage($this->translate(
                                'A contact with the same username already exists.'
                            ));

                            return false;
                        }

                        return true;
                    })
                ]
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
        $changedAt = (int) (new DateTime())->format("Uv");
        $this->db->beginTransaction();
        $this->db->insert('contact', $contactInfo['contact'] + ['changed_at' => $changedAt]);
        $this->contactId = $this->db->lastInsertId();

        foreach (array_filter($contactInfo['contact_address']) as $type => $address) {
            $address = [
                'contact_id' => $this->contactId,
                'type'       => $type,
                'address'    => $address,
                'changed_at' => $changedAt
            ];

            $this->db->insert('contact_address', $address);
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

        $changedAt = (int) (new DateTime())->format("Uv");
        if ($storedValues['contact'] !== $values['contact']) {
            $this->db->update(
                'contact',
                $values['contact'] + ['changed_at' => $changedAt],
                ['id = ?' => $this->contactId]
            );
        }

        $storedAddresses = $storedValues['contact_address_with_id'];
        foreach ($values['contact_address'] as $type => $address) {
            if ($address === null) {
                if (isset($storedAddresses[$type])) {
                    $this->db->update(
                        'contact_address',
                        ['changed_at' => $changedAt, 'deleted' => 'y'],
                        ['id = ?' => $storedAddresses[$type][0], 'deleted = ?' => 'n']
                    );
                }
            } elseif (! isset($storedAddresses[$type])) {
                $address = [
                    'contact_id' => $this->contactId,
                    'type'       => $type,
                    'address'    => $address,
                    'changed_at' => $changedAt
                ];

                $this->db->insert('contact_address', $address);
            } elseif ($storedAddresses[$type][1] !== $address) {
                $this->db->update(
                    'contact_address',
                    ['address' => $address, 'changed_at' => $changedAt],
                    [
                        'id = ?'         => $storedAddresses[$type][0],
                        'contact_id = ?' => $this->contactId
                    ]
                );
            }
        }

        $this->db->commitTransaction();
    }

    /**
     * Remove the contact
     */
    public function removeContact(): void
    {
        $this->db->beginTransaction();

        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];
        $updateCondition = ['contact_id = ?' => $this->contactId, 'deleted = ?' => 'n'];

        $rotationAndMemberIds = $this->db->fetchPairs(
            RotationMember::on($this->db)
                ->columns(['id', 'rotation_id'])
                ->filter(Filter::equal('contact_id', $this->contactId))
                ->assembleSelect()
        );

        $rotationMemberIds = array_keys($rotationAndMemberIds);
        $rotationIds = array_values($rotationAndMemberIds);

        $this->db->update('rotation_member', $markAsDeleted + ['position' => null], $updateCondition);

        if (! empty($rotationMemberIds)) {
            $this->db->update(
                'timeperiod_entry',
                $markAsDeleted,
                ['rotation_member_id IN (?)' => $rotationMemberIds, 'deleted = ?' => 'n']
            );
        }

        if (! empty($rotationIds)) {
            $rotationIdsWithOtherMembers = $this->db->fetchCol(
                RotationMember::on($this->db)
                    ->columns('rotation_id')
                    ->filter(
                        Filter::all(
                            Filter::equal('rotation_id', $rotationIds),
                            Filter::unequal('contact_id', $this->contactId)
                        )
                    )->assembleSelect()
            );

            $toRemoveRotations = array_diff($rotationIds, $rotationIdsWithOtherMembers);

            if (! empty($toRemoveRotations)) {
                $rotations = Rotation::on($this->db)
                    ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
                    ->filter(Filter::equal('id', $toRemoveRotations));

                /** @var Rotation $rotation */
                foreach ($rotations as $rotation) {
                    $rotation->delete();
                }
            }
        }

        $escalationIds = $this->db->fetchCol(
            RuleEscalationRecipient::on($this->db)
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('contact_id', $this->contactId))
                ->assembleSelect()
        );

        $this->db->update('rule_escalation_recipient', $markAsDeleted, $updateCondition);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->db->fetchCol(
                RuleEscalationRecipient::on($this->db)
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('contact_id', $this->contactId)
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $this->db->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $this->db->update('contactgroup_member', $markAsDeleted, $updateCondition);
        $this->db->update('contact_address', $markAsDeleted, $updateCondition);

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
            ->filter(Filter::equal('id', $this->contactId))
            ->first();

        if ($contact === null) {
            throw new HttpNotFoundException(t('Contact not found'));
        }

        $values['contact'] =  [
            'full_name'          => $contact->full_name,
            'username'           => $contact->username,
            'default_channel_id' => (string) $contact->default_channel_id
        ];

        $values['contact_address'] = [];
        $values['contact_address_with_id'] = []; //TODO: only used in editContact(), find better solution
        foreach ($contact->contact_address as $contactInfo) {
            $values['contact_address'][$contactInfo->type] = $contactInfo->address;
            $values['contact_address_with_id'][$contactInfo->type] = [$contactInfo->id, $contactInfo->address];
        }

        return $values;
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
            $element = $this->createElement('text', $type, [
                'label'      => $label,
                'validators' => [new StringLengthValidator(['max' => 255])]
            ]);

            if ($type === 'email') {
                $element->addAttributes(['validators' => [new EmailAddressValidator()]]);
            }

            $address->addElement($element);
        }
    }
}
