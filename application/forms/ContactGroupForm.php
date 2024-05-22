<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
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

    /** @var TermInput */
    private $termInput;

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

        $this->termInput = (new TermInput(
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

        $this->addElement('text',
            'group_name',
            [
                'label'    => $this->translate('Name'),
                'required' => true
            ]
        )->addElement($this->termInput);

        $this->addElement('submit', 'submit', ['label' => $this->translate('Submit')]);

        $buttonCancel = new SubmitElement(
            'cancel',
            [
                'label'          => $this->translate('Cancel'),
                'class'          => 'btn-cancel',
                'formnovalidate' => true
            ]
        );

        // bind cancel button and add it in front of the submit button
        $this->registerElement($buttonCancel);
        $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent($buttonCancel));
    }

    /**
     * Check if the cancel button has been pressed
     *
     * @return bool
     */
    public function hasBeenCancelled(): bool
    {
        $btn = $this->getPressedSubmitElement();

        return $btn !== null && $btn->getName() === 'cancel';
    }

    /**
     * Get part updates
     *
     * @return array
     */
    public function getPartUpdates(): array
    {
        $this->ensureAssembled();

        return $this->termInput->prepareMultipartUpdate($this->request);
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
     * Add a new contact group
     *
     * @return ?int
     */
    public function addGroup(): ?int
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
}
