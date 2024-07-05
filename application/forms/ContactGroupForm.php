<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Web\Session;
use ipl\Html\FormElement\SubmitElement;
use ipl\Html\HtmlDocument;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\TermInput;
use ipl\Web\FormElement\TermInput\Term;

class ContactGroupForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var Connection */
    private $db;

    /** @var ?int Contact group id */
    private $contactgroupId;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    protected function assemble(): void
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        $callValidation = function (array $terms) {
            $this->validateTerms($terms);
        };

        $termInput = (new TermInput(
            'group_members',
            [
                'label'    => $this->translate('Members')
            ]
        ))
            ->setVerticalTermDirection()
            ->setSuggestionUrl(
                Links::contactGroupsSuggestMember()->with(['showCompact' => true, '_disableLayout' => 1])
            )
            ->on(TermInput::ON_ENRICH, $callValidation)
            ->on(TermInput::ON_ADD, $callValidation)
            ->on(TermInput::ON_SAVE, $callValidation)
            ->on(TermInput::ON_PASTE, $callValidation);

        $this->addElement(
            'text',
            'group_name',
            [
                'label'    => $this->translate('Name'),
                'required' => true
            ]
        )->addElement($termInput);

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $this->contactgroupId
                    ? $this->translate('Save Changes')
                    : $this->translate('Add Contact Group')
            ]
        );

        if ($this->contactgroupId) {
            $removeBtn = new SubmitElement(
                'remove',
                [
                    'label'             => $this->translate('Remove'),
                    'class'             => 'btn-remove',
                    'formnovalidate'    => true
                ]
            );

            $this->registerElement($removeBtn);
            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent($removeBtn));
        }
    }

    /**
     * Check if the cancel button has been pressed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'remove';
    }

    /**
     * Get part updates
     *
     * @return array
     */
    public function getPartUpdates(): array
    {
        $this->ensureAssembled();

        return $this->getElement('group_members')->prepareMultipartUpdate($this->getRequest());
    }

    /**
     * Validate the terms
     *
     * @param Term[] $terms
     */
    protected function validateTerms(array $terms): void
    {
        $contactTerms = [];
        foreach ($terms as $term) {
            $searchValue = $term->getSearchValue();
            if (! is_numeric($searchValue)) {
                $term->setMessage($this->translate('Is not a contact'));

                continue;
            }

            $contactTerms[$searchValue] = $term;
        }

        if (! empty($contactTerms)) {
            $contacts = (Contact::on($this->db))
                ->filter(Filter::equal('id', array_keys($contactTerms)));

            foreach ($contacts as $contact) {
                $contactTerms[$contact->id]
                    ->setLabel($contact->full_name)
                    ->setClass('contact');
            }
        }
    }

    /**
     * Load a contact group and populate the form
     *
     * @param int $groupId
     *
     * @return $this
     */
    public function loadContactgroup(int $groupId): self
    {
        $this->contactgroupId = $groupId;

        $this->populate($this->fetchDbValues());

        return $this;
    }

    /**
     * Add a new contact group
     *
     * @return int
     */
    public function addGroup(): int
    {
        $data = $this->getValues();

        $this->db->beginTransaction();

        $this->db->insert('contactgroup', ['name' => trim($data['group_name'])]);

        $groupIdentifier = $this->db->lastInsertId();

        $contactIds = [];
        if (! empty($data['group_members'])) {
            $contactIds = explode(',', $data['group_members']);
        }

        foreach ($contactIds as $contactId) {
            $this->db->insert(
                'contactgroup_member',
                [
                    'contactgroup_id' => $groupIdentifier,
                    'contact_id'      => $contactId
                ]
            );
        }

        $this->db->commitTransaction();

        return $groupIdentifier;
    }

    /**
     * Edit the contact group
     *
     * @return void
     */
    public function editGroup(): void
    {
        $values = $this->getValues();

        $this->db->beginTransaction();

        $storedValues = $this->fetchDbValues();

        $changedAt = time() * 1000;
        if ($values['group_name'] !== $storedValues['group_name']) {
            $this->db->update(
                'contactgroup',
                ['name' => $values['group_name'], 'changed_at' => $changedAt],
                ['id = ?' => $this->contactgroupId]
            );
        }

        $storedContacts = [];
        if (! empty($storedValues['group_members'])) {
            $storedContacts = explode(',', $storedValues['group_members']);
        }

        $newContacts = [];
        if (! empty($values['group_members'])) {
            $newContacts = explode(',', $values['group_members']);
        }

        $toDelete = array_diff($storedContacts, $newContacts);
        $toAdd = array_diff($newContacts, $storedContacts);

        if (! empty($toDelete)) {
            $this->db->update(
                'contactgroup_member',
                ['changed_at' => $changedAt, 'deleted' => 'y'],
                [
                    'contactgroup_id = ?'   => $this->contactgroupId,
                    'contact_id IN (?)'     => $toDelete
                ]
            );
        }

        if (! empty($toAdd)) {
            $contactsMarkedAsDeleted = $this->db->fetchCol(
                (new Select())
                    ->from('contactgroup_member')
                    ->columns(['contact_id'])
                    ->where([
                        'contactgroup_id = ?'   => $this->contactgroupId,
                        'deleted = ?'           => 'y',
                        'contact_id IN (?)'     => $toAdd
                    ])
            );

            $toAdd = array_diff($toAdd, $contactsMarkedAsDeleted);
            foreach ($toAdd as $contactId) {
                $this->db->insert(
                    'contactgroup_member',
                    [
                        'contactgroup_id'   => $this->contactgroupId,
                        'contact_id'        => $contactId
                    ]
                );
            }

            if (! empty($contactsMarkedAsDeleted)) {
                $this->db->update(
                    'contactgroup_member',
                    ['changed_at' => $changedAt, 'deleted' => 'n'],
                    [
                        'contactgroup_id = ?'   => $this->contactgroupId,
                        'contact_id IN (?)'     => $contactsMarkedAsDeleted
                    ]
                );
            }
        }

        $this->db->commitTransaction();
    }

    /**
     * Remove the contact group
     */
    public function removeContactgroup(): void
    {
        $this->db->beginTransaction();

        $markAsDeleted = ['changed_at' => time() * 1000, 'deleted' => 'y'];

        $rotationIds = $this->db->fetchCol(
            RotationMember::on($this->db)
                ->columns('rotation_id')
                ->filter(Filter::equal('contactgroup_id', $this->contactgroupId))
                ->assembleSelect()
        );

        $this->db->update(
            'rotation_member',
            $markAsDeleted + ['position' => null],
            ['contactgroup_id = ?' => $this->contactgroupId]
        );

        if (! empty($rotationIds)) {
            $rotationIdsWithOtherMembers = $this->db->fetchCol(
                RotationMember::on($this->db)
                    ->columns('rotation_id')
                    ->filter(Filter::all(
                        Filter::equal('rotation_id', $rotationIds),
                        Filter::unequal('contactgroup_id', $this->contactgroupId),
                        Filter::equal('deleted', 'n')
                    ))->assembleSelect()
            );

            $toRemoveRotations = array_diff($rotationIds, $rotationIdsWithOtherMembers);

            if (! empty($toRemoveRotations)) {
                $this->db->update(
                    'rotation',
                    $markAsDeleted + ['priority' => null, 'first_handoff' => null],
                    ['id IN (?)' => $toRemoveRotations]
                );
            }
        }

        $this->db->update(
            'rule_escalation_recipient',
            $markAsDeleted,
            ['contactgroup_id = ?' => $this->contactgroupId]
        );

        $this->db->update('contactgroup_member', $markAsDeleted, ['contactgroup_id = ?' => $this->contactgroupId]);
        $this->db->update('contactgroup', $markAsDeleted, ['id = ?' => $this->contactgroupId]);

        $this->db->commitTransaction();
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
        $query = Contactgroup::on($this->db)
            ->columns(['id', 'name'])
            ->filter(Filter::all(
                Filter::equal('id', $this->contactgroupId),
                Filter::equal('deleted', 'n')
            ));

        $group = $query->first();
        if ($group === null) {
            throw new HttpNotFoundException($this->translate('Contact group not found'));
        }

        $contacts = Contact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('contactgroup_member.contactgroup_id', $group->id),
                Filter::equal('contactgroup_member.deleted', 'n'),
                Filter::equal('deleted', 'n')
            ));

        $groupMembers = [];
        foreach ($contacts as $contact) {
            $groupMembers[] = $contact->id;
        }

        return [
            'group_name'        => $group->name,
            'group_members'     => implode(',', $groupMembers)
        ];
    }
}
