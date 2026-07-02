<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Html\HtmlDocument;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormDecorator\IcingaFormDecorator;
use ipl\Web\FormElement\TermInput;
use ipl\Web\FormElement\TermInput\Term;

class ContactGroupForm extends CompatForm
{
    use CsrfCounterMeasure;

    private ConfigProviderInterface $configProvider;

    /**
     * Set the contact group to populate the form with
     *
     * @param Contactgroup $contactGroup
     *
     * @return $this
     */
    public function setContactGroup(Contactgroup $contactGroup): static
    {
        $this->populate($this->contactGroupToFormData($contactGroup));

        return $this;
    }

    /**
    * Get the contact group as it's currently configured
    *
    * @return ContactGroupData
    */
    public function getContactGroup(): ContactGroupData
    {
        return new ContactGroupData(
            $this->getValue('group_id'),
            trim($this->getValue('group_name', '')),
            array_map(intval(...), array_filter(explode(',', $this->getValue('group_members'))))
        );
    }

    public function __construct(ConfigProviderInterface $configProvider)
    {
        $this->configProvider = $configProvider;

        $this->applyDefaultElementDecorators();
    }

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure(Session::getSession()->getId());

        $this->addElement('hidden', 'group_id');
        $groupId = $this->getPopulatedValue('group_id') ?: null;
        if ($groupId !== null) {
            $groupId = (int) $groupId;
        }

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
            ->setReadOnly()
            ->setSuggestionUrl(
                Links::contactGroupsSuggestMember()->with(['showCompact' => true, '_disableLayout' => 1])
            )
            ->on(TermInput::ON_ENRICH, $callValidation)
            ->on(TermInput::ON_ADD, $callValidation)
            ->on(TermInput::ON_SAVE, $callValidation)
            ->on(TermInput::ON_PASTE, $callValidation);

        // TODO: TermInput is not compatible with the new decorators yet: https://github.com/Icinga/ipl-web/pull/317
        $legacyDecorator = new IcingaFormDecorator();
        $termInput->setDefaultElementDecorator($legacyDecorator);
        $legacyDecorator->decorate($termInput);

        $this->addElement(
            'text',
            'group_name',
            [
                'label'    => $this->translate('Name'),
                'required' => true,
                'validators' => [
                    new CallbackValidator(function ($value, $validator) use ($groupId) {
                        $group = $this->configProvider->findContactGroupByName(
                            $value,
                            $this->hasBeenDuplicated() ? null : $groupId
                        );
                        if ($group !== null) {
                            $validator->addMessage($this->translate(
                                'A contact group with this name already exists'
                            ));

                            return false;
                        }

                        return true;
                    })
                ]
            ]
        )->addElement($termInput);

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $groupId === null
                    ? $this->translate('Create Contact Group')
                    : $this->translate('Save Changes')
            ]
        );

        if ($groupId !== null) {
            $deleteBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);

            $this->registerElement($deleteBtn);

            $duplicateBtn = $this->createElement('submit', 'duplicate', [
                'label' => $this->translate('Duplicate')
            ]);
            $this->registerElement($duplicateBtn);

            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent(
                $deleteBtn,
                $duplicateBtn
            ));
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

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    /**
     * Get whether the duplicate button was pressed
     *
     * @return bool
     */
    public function hasBeenDuplicated(): bool
    {
        return $this->getPressedSubmitElement()?->getName() === 'duplicate';
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
            $contacts = $this->configProvider->findContactsByIds(array_keys($contactTerms));
            foreach ($contacts as $contact) {
                $contactTerms[$contact->id]
                    ->setLabel($contact->full_name)
                    ->setClass('contact');
            }
        }
    }

    /**
     * Transform the current contact group into form data
     *
     * @param Contactgroup $group
     *
     * @return array
     */
    private function contactGroupToFormData(Contactgroup $group): array
    {
        $groupMembers = [];
        foreach ($group->contactgroup_member as $contact) {
            $groupMembers[] = $contact->contact_id;
        }

        return [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'group_members' => implode(',', $groupMembers)
        ];
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || ($this->hasBeenSent() && $this->hasBeenDuplicated());
    }

    protected function onError()
    {
        // TODO: I feel like this should be the case in ipl-html already
        $this->emit(Form::ON_SENT, [$this]);
    }
}
