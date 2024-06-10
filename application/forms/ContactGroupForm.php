<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Web\Session;
use ipl\Html\FormElement\SubmitElement;
use ipl\Html\HtmlDocument;
use ipl\Sql\Connection;
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
                'label'    => $this->translate('Members'),
                'required' => true
            ]
        ))
            ->setVerticalTermDirection()
            ->setSuggestionUrl(Links::contactGroupsSuggestMember())
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
     *
     * @return void
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
            $contacts = (Contact::on(Database::get()))
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

        $this->db->insert(
            'contactgroup',
            [
                'name'  => trim($data['group_name']),
                'color' => '#000000'
            ]
        );

        $groupIdentifier = $this->db->lastInsertId();
        $contactIds = explode(',', $data['group_members']);

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
     * @return bool False if no changes found, true otherwise
     */
    public function editGroup(): bool
    {
        $isUpdated = false;
        $values = $this->getValues();

        $this->db->beginTransaction();

        $storedValues = $this->fetchDbValues();

        if ($values['group_name'] !== $storedValues['group_name']) {
            $this->db->update(
                'contactgroup',
                ['name' => $values['group_name']],
                ['id = ?' => $this->contactgroupId]
            );

            $isUpdated = true;
        }

        $storedContacts =  explode(',', $storedValues['group_members']);
        $newContacts = explode(',', $values['group_members']);

        $toDelete = array_diff($storedContacts, $newContacts);
        $toAdd = array_diff($newContacts, $storedContacts);

        if (! empty($toDelete)) {
            $this->db->delete('contactgroup_member', ['contact_id IN (?)' => $toDelete]);

            $isUpdated = true;
        }

        if (! empty($toAdd)) {
            foreach ($toAdd as $contactId) {
                $this->db->insert(
                    'contactgroup_member',
                    [
                        'contactgroup_id' => $this->contactgroupId,
                        'contact_id'      => $contactId
                    ]
                );
            }

            $isUpdated = true;
        }

        $this->db->commitTransaction();

        return $isUpdated;
    }

    /**
     * Remove the contact group
     */
    public function removeContactgroup(): void
    {
        $this->db->beginTransaction();

        $this->db->delete('contactgroup_member', ['contactgroup_id = ?' => $this->contactgroupId]);
        $this->db->delete('contactgroup', ['id = ?' => $this->contactgroupId]);

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
        $query = Contactgroup::on(Database::get())
            ->columns(['id', 'name'])
            ->filter(Filter::equal('id', $this->contactgroupId));

        $group = $query->first();
        if ($group === null) {
            throw new HttpNotFoundException($this->translate('Contact group not found'));
        }

        $groupMembers = [];
        foreach ($group->contact->columns('id') as $contact) {
            $groupMembers[] = $contact->id;
        }

        return [
            'group_name'        => $group->name,
            'group_members'     => implode(',', $groupMembers)
        ];
    }
}
