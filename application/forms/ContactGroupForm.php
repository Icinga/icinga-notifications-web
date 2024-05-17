<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Web\Session;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitElement;
use ipl\Html\FormElement\TextElement;
use ipl\Html\HtmlDocument;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\TermInput;
use ipl\Web\FormElement\TermInput\Term;
use PDOException;

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

    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        // initialize form fields
        $groupField = new FieldsetElement(
            'group',
            [
                'label' => $this->translate('Properties')
            ]
        );
        $this->addElement($groupField);

        // build form fields
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
        $groupField
            ->addElement(
                new TextElement(
                    'group_name',
                    [
                        'label'    => $this->translate('Name'),
                        'required' => true
                    ]
                )
            )
            ->addElement($this->termInput);

        // add form actions
        $buttonSubmit = $this->addElement(
            new SubmitElement(
                'submit',
                [
                    'label' => $this->translate('Submit')
                ]
            )
        );
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
        $buttonSubmit->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent($buttonCancel));
    }

    public function hasBeenCancelled(): bool
    {
        $btn = $this->getPressedSubmitElement();

        return $btn !== null && $btn->getName() === 'cancel';
    }

    public function getPartUpdates(): array
    {
        $this->ensureAssembled();

        return $this->termInput->prepareMultipartUpdate($this->request);
    }

    protected function validateTerms($terms): void
    {
        $contactTerms = [];
        foreach ($terms as $term) {
            /** @var Term $term */
            if (strpos($term->getSearchValue(), ':') === false) {
                $term->setMessage($this->translate('Is not a contact'));
                continue;
            }

            list($type, $id) = explode(':', $term->getSearchValue(), 2);
            if ($type === 'contact') {
                $contactTerms[$id] = $term;
            }
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
     * @return ?int
     */
    public function addGroup()
    {
        $data = $this->getValues();
        $groupIdentifier = null;

        if (! array_key_exists('group', $data)) {
            return null;
        } elseif (
            ! array_key_exists('group_name', $data['group'])
            || ! array_key_exists('group_members', $data['group'])
        ) {
            return null;
        }

        $members = array_map(function ($contact) {
            return explode(':', $contact, 2);
        }, explode(',', $data['group']['group_members']));

        $this->db->beginTransaction();

        $this->db->insert(
            'contactgroup',
            [
                'name'  => trim($data['group']['group_name']),
                'color' => '#000000'
            ]
        );
        $groupIdentifier = $this->db->lastInsertId();

        foreach ($members as list($type, $identifier)) {
            if ($type === 'contact') {
                $this->db->insert(
                    'contactgroup_member',
                    [
                        'contactgroup_id' => $groupIdentifier,
                        'contact_id'      => $identifier
                    ]
                );
            }
        }

        $this->db->commitTransaction();

        return $groupIdentifier ?? intval($groupIdentifier);
    }
}
