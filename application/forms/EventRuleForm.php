<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Form\Data\Rule as RuleData;
use Icinga\Module\Notifications\Model\Rule;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CalloutType;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Widget\Callout;

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
     * @return RuleData
     */
    public function getRule(): RuleData
    {
        $id = $this->getValue('id');
        if ($id !== null) {
            $id = (int) $id;
        }

        return new RuleData(
            $id,
            $this->getValue('name'),
            $this->getValue('type'),
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
        return $this->getPressedSubmitElement()?->getName() === 'delete';
    }

    /**
     * Check if the duplicate button was pressed
     *
     * @return bool
     */
    public function hasBeenDuplicated(): bool
    {
        return $this->getPressedSubmitElement()?->getName() === 'duplicate';
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
                'required'      => true,
                'label'         => $this->translate('Title'),
                'validators'    => [
                    new CallbackValidator(function ($value, $validator) use ($ruleId) {
                        if ($this->hasBeenDeleted()) {
                            return true;
                        }

                        $rules = Rule::on(Database::get())
                            ->columns('id')
                            ->filter(Filter::equal('type', $this->getValue('type')))
                            ->filter(Filter::equal('name', $value));
                        if ($ruleId !== null && ! $this->hasBeenDuplicated()) {
                            $rules->filter(Filter::unequal('id', $ruleId));
                        }

                        if ($rules->first() !== null) {
                            $validator->addMessage(match ($this->getValue('type')) {
                                'escalation' => $this->translate('An escalation rule with this name already exists.'),
                                'notification' => $this->translate('A notification rule with this name already exists.')
                            });

                            return false;
                        }

                        return true;
                    })
                ]
            ]
        );

        $isNotificationRule = $ruleId !== null && $this->getPopulatedValue('type') === "notification";
        $disabledOptions = [''];
        if ($isNotificationRule) {
            foreach ($this->sourceTypes as $key => $type) {
                if (! $this->hasEventTypes($key) && $this->getPopulatedValue('source_type') !== $key) {
                    $disabledOptions[] = $key;
                }
            }
        }

        $this->addElement('select', 'source_type', [
            'label' => $this->translate('Source Type'),
            'required' => true,
            'options' => ['' => ' - ' . $this->translate('Please choose') . ' - '] + $this->sourceTypes,
            'disabledOptions' => $disabledOptions,
            'value' => '',
            'class' => 'autosubmit',
        ]);

        $chosenSource = $this->getValue('source_type') ?? "";
        $hasEventTypes = $this->hasEventTypes($chosenSource);

        // In case the source is a hook but provides no event types, display warning
        if (! $hasEventTypes) {
            $warnMessage = $this->translate(
                'Notification rules are not supported for this source type, as it provides no event types.'
            );
            if ($isNotificationRule) {
                $warnMessage .= " " . $this->translate(
                    'This rule should be deleted, because it will never match.'
                );
            }
        }

        if ($ruleId !== null) {
            $this->getElement('source_type')
                ->setDescription(
                    match ($this->getPopulatedValue('type')) {
                        'escalation' => $this->translate(
                            'Choosing a different source type will reset all filters of the rule'
                        ),
                        'notification' => $this->translate(
                            'Choosing a different source type will reset all filters and event types of the rule'
                        )
                    }
                )
                ->getDecorators()
                ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);
        }

        if (! $hasEventTypes && ($isNotificationRule || $ruleId === null)) {
            $this->addHtml(Html::tag('div', ['class' => 'control-group'], [
                Html::tag('div', ['class' => 'control-label-group']),
                new Callout(
                    $isNotificationRule ? CalloutType::Warning : CalloutType::Info,
                    Html::tag('span', [$warnMessage])
                ),
            ]));
        }

        $this->assembleTypeSelection($ruleId !== null, $hasEventTypes);

        $this->addElement('submit', 'btn_submit', [
            'label' => $ruleId === null
                ? $this->translate('Create Event Rule')
                : $this->translate('Save Changes')
        ]);

        if ($ruleId !== null) {
            $deleteBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete Event Rule'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($deleteBtn);

            $duplicateBtn = $this->createElement('submit', 'duplicate', [
                'label' => $this->translate('Duplicate Event Rule')
            ]);
            $this->registerElement($duplicateBtn);

            $this->getElement('btn_submit')->prependWrapper((new HtmlDocument())->setHtmlContent(
                $deleteBtn,
                $duplicateBtn
            ));
        }
    }

    protected function assembleTypeSelection(bool $disabled, bool $hasEventTypes): void
    {
        $value = $this->getPopulatedValue('type');
        if ($disabled) {
            // Preserve the populated value in a hidden field to avoid losing it when the form is submitted
            $this->addElement('hidden', 'type', ['value' => $value]);
        }

        $types = [
            'notification' => $this->translate('Notification Rule'),
            'escalation' => $this->translate('Escalation Rule')
        ];

        $modeList = new HtmlElement('ul', Attributes::create([
            'class' => ['pictogram-selection', $disabled ? 'disabled' : '']
        ]));

        // Clear populated value if it was on a type that is not available
        if (! $disabled && $value === 'notification' && ! $hasEventTypes) {
            $this->clearPopulatedValue('type');
            $value = null;
        }

        foreach ($types as $type => $label) {
            $typeUnavailable = ! $disabled && $type === 'notification' && ! $hasEventTypes;
            $radio = $this->createElement('input', 'type', [
                'type' => 'radio',
                'value' => $type,
                'required' => true,
                'disabled' => $disabled || $typeUnavailable,
                'id' => 'rule-type-' . $type
            ]);

            // Check type if it is available and if rule existed before
            if (! $typeUnavailable && ($type === $value || $value === null)) {
                $radio->getAttributes()->set('checked', true);
                $this->registerElement($radio);
                $value = $type;
            }

            $labelDescription = match ($type) {
                'notification' => new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate(
                        'Notify a fixed set of recipients as soon as an event matches the filter.'
                    ))
                ),
                'escalation' => new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate(
                        'Notify recipients in stages and escalate based on incident age or severity.'
                    ))
                )
            };

            $modeList->addHtml(new HtmlElement(
                'li',
                null,
                new HtmlElement(
                    'label',
                    null,
                    $radio,
                    new HtmlElement('div', Attributes::create(['class' => ['pictogram', 'img-' . $type]])),
                    Text::create($label),
                    $labelDescription
                )
            ));
        }

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create([
                'class' => ['control-group']
            ]),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'control-label-group']),
                Text::create($this->translate('Rule Type') . ' *')
            ),
            $modeList
        ));
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
            'type' => $rule->type,
            'source_type' => $rule->source_type
        ];
    }

    /**
     * Check if the hook has event types
     *
     * @param string $type
     *
     * @return bool
     */
    private function hasEventTypes(string $type): bool
    {
        $hook = SourceHookLocator::forType($type);

        return $hook === null || ! empty($hook->getEventTypes());
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || (
            $this->hasBeenSent() && ($this->hasBeenDeleted() || $this->hasBeenDuplicated())
        );
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
