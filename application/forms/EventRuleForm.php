<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Form\Data\EscalationRule;
use Icinga\Module\Notifications\Model\Rule;
use ipl\Html\Contract\Form;
use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\Html\HtmlDocument;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class EventRuleForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var array<string, string> */
    protected array $sourceTypes = [];

    /**
     * Set the source types to choose from
     *
     * @param string[] $sourceTypes
     *
     * @return $this
     */
    public function setAvailableSourceTypes(array $sourceTypes): static
    {
        $this->sourceTypes = [];
        foreach ($sourceTypes as $type) {
            $this->sourceTypes[$type] = SourceHookLocator::labelFor($type);
        }

        return $this;
    }

    /**
     * Set the rule to populate the form with
     *
     * @param Rule $rule
     *
     * @return $this
     */
    public function setRule(Rule $rule): static
    {
        $this->populate($this->ruleToFormData($rule));

        return $this;
    }

    /**
     * Get the rule as it's currently configured
     *
     * @return EscalationRule
     */
    public function getRule(): EscalationRule
    {
        $id = $this->getValue('id');
        if ($id !== null) {
            $id = (int) $id;
        }

        return new EscalationRule(
            $id,
            $this->getValue('name'),
            $this->getValue('source_type'),
            null
        );
    }

    /**
     * Check if the delete button was pressed
     *
     * @return bool
     */
    public function hasBeenDeleted(): bool
    {
        $btn = $this->getPressedSubmitElement();

        return $btn !== null && $btn->getName() === 'delete';
    }

    protected function assemble(): void
    {
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();

        $this->addElement('hidden', 'id');
        $ruleId = $this->getPopulatedValue('id') ?: null;
        if ($ruleId !== null) {
            $ruleId = (int) $ruleId;
        }

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Title'),
                'required'  => true
            ]
        );

        $this->addElement('select', 'source_type', [
            'label' => $this->translate('Source Type'),
            'required' => true,
            'options' => ['' => ' - ' . $this->translate('Please choose') . ' - '] + $this->sourceTypes,
            'disabledOptions' => [''],
            'value' => ''
        ]);
        if ($ruleId !== null) {
            $this->getElement('source_type')
                ->setDescription($this->translate(
                    'Choosing a different source type will reset all filters of the rule'
                ))
                ->getDecorators()
                ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);
        }

        $this->addElement('submit', 'btn_submit', [
            'label' => $ruleId === null
                ? $this->translate('Create Event Rule')
                : $this->translate('Save Changes')
        ]);

        if ($ruleId !== null) {
            $deleteBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);

            $this->registerElement($deleteBtn);

            $this->getElement('btn_submit')->prependWrapper((new HtmlDocument())->setHtmlContent(
                $deleteBtn
            ));
        }
    }

    /**
     * Transform the given rule into form data
     *
     * @param Rule $rule
     *
     * @return array<string, mixed>
     */
    private function ruleToFormData(Rule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'source_type' => $rule->source_type
        ];
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || ($this->hasBeenSent() && $this->hasBeenDeleted());
    }

    protected function onError()
    {
        parent::onError();

        // TODO: I feel like this should be the case in ipl-html already
        if (! $this->hasMessages()) {
            // Trigger the event in case only validation failed
            $this->emit(Form::ON_ERROR, [null, $this]);
        }
    }
}
