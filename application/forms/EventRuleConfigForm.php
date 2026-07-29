<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Form\Data\EscalationRule;
use Icinga\Module\Notifications\Model\Rule;
use ipl\Html\Attributes;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleConfigForm extends CompatForm
{
    use CsrfCounterMeasure;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['event-rule-config', 'icinga-controls'],
        'name'  => 'event-rule-config-form',
        'id'    => 'event-rule-config-form'
    ];

    protected ConfigProviderInterface $configProvider;

    /** @var Url Search editor URL for the config filter fieldset */
    protected Url $searchEditorUrl;

    /**
     * Create a new EventRuleConfigForm
     *
     * @param ConfigProviderInterface $configProvider
     * @param Url $searchEditorUrl
     */
    public function __construct(ConfigProviderInterface $configProvider, Url $searchEditorUrl)
    {
        $this->configProvider = $configProvider;
        $this->searchEditorUrl = $searchEditorUrl;

        $this->addElementLoader('Icinga\\Module\\Notifications\\Forms\\EventRuleConfigElements');
        $this->applyDefaultElementDecorators();
    }

    /**
     * Load the given escalation rule into the form
     *
     * @param Rule $rule
     *
     * @return void
     */
    public function setRule(Rule $rule): void
    {
        $fields = [
            'id' => $rule->id,
            'name' => $rule->name,
            'source' => $rule->source_id,
            'object_filter' => $rule->object_filter
        ];

        $escalations = $rule->rule_escalation->orderBy('position', 'asc')->execute();
        if ($escalations->hasResult()) {
            $fields['escalations'] = EventRuleConfigElements\Escalations::prepare(
                $escalations
            );
        }

        $this->populate($fields);
    }

    /**
     * Get the rule as currently configured by the user
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
            (int) $this->getValue('source'),
            $this->getValue('object_filter'),
            $this->getElement('escalations')->getEscalations($id)
        );
    }

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure();

        // Replicate the save button outside the form
        $this->addElement(
            'submitButton',
            'save',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        // Replicate the delete button outside the form
        $this->addElement(
            'submitButton',
            'delete',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        $this->addHtml(
            new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
            new HtmlElement(
                'div',
                Attributes::create(['id' => 'object-filter-controls']),
                $this->createObjectFilterControls()
            ),
            new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
        );

        $this->addElement('escalations', 'escalations', [
            'decorators'    => [],
            'provider'      => $this->configProvider,
            'required'      => true
        ]);

        $this->addElement('hidden', 'id');

        $name = $this->createElement('hidden', 'name', ['required' => true]);
        $this->registerElement($name);
        $source = $this->createElement('hidden', 'source', ['required' => true]);
        $this->registerElement($source);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['id' => 'event-rule-config-form-name', 'hidden' => true]),
            $name,
            $source
        ));
    }

    /**
     * Create and return the controls to configure the object filter
     *
     * @return ValidHtml
     */
    protected function createObjectFilterControls(): ValidHtml
    {
        $hiddenInput = $this->createElement('hidden', 'object_filter');
        $this->registerElement($hiddenInput);

        if ($hiddenInput->hasValue()) {
            $parsedFilter = json_decode($hiddenInput->getValue(), true, flags: JSON_THROW_ON_ERROR);

            $icon = 'filter';
            if (! empty($parsedFilter['filter_name'])) {
                $text = $parsedFilter['filter_name'];
                $title = sprintf(
                    '%s (%s: %s)',
                    $this->translate('Adjust Filter'),
                    $this->translate('Name'),
                    $parsedFilter['filter_name']
                );
            } else {
                $text = $this->translate('Adjust Filter');
                $title = $text;
            }
        } else {
            $icon = 'plus';
            $text = $this->translate('Add Filter');
            $title = $text;
        }

        return new HtmlElement(
            'div',
            Attributes::create(['class' => 'button-wrapper']),
            new Link(
                [
                    new Icon($icon),
                    new HtmlElement('span', content: Text::create($text))
                ],
                $this->searchEditorUrl,
                Attributes::create([
                    'class'               => ['search-editor-opener', 'filter-button'],
                    'title'               => $title,
                    'data-icinga-modal'   => true,
                    'data-no-icinga-ajax' => true
                ])
            ),
            $hiddenInput
        );
    }

    /**
     * Get the element to update in case the config of the rule is changed
     *
     * @param string $newName
     * @param int $newSource
     *
     * @return ValidHtml
     */
    public function prepareConfigUpdate(string $newName, int $newSource): ValidHtml
    {
        return new HtmlElement(
            'div',
            Attributes::create(['id' => 'event-rule-config-form-name']),
            $this->createElement('hidden', 'name', ['required' => true, 'value' => $newName]),
            $this->createElement('hidden', 'source', ['required' => true, 'value' => $newSource])
        );
    }

    /**
     * Get the element to update in case the object filter of the rule is changed
     *
     * @param ?string $newFilter
     *
     * @return ValidHtml
     */
    public function prepareObjectFilterUpdate(?string $newFilter): ValidHtml
    {
        if ($newFilter !== null) {
            $this->populate(['object_filter' => $newFilter]);
        }

        return new HtmlElement(
            'div',
            Attributes::create(['id' => 'object-filter-controls']),
            $this->createObjectFilterControls()
        );
    }

    /**
     * Create and return the submit-buttons for the form
     *
     * @return SubmitButtonElement[]
     */
    public function createExternalSubmitButtons(): array
    {
        $buttons = [
            $this->createElement('submitButton', 'save', [
                'data-progress-label' => $this->translate('Saving rule'),
                'label' => $this->translate('Save'),
                'form' => 'event-rule-config-form'
            ])
        ];

        if ($this->getValue('id') !== null) {
            $buttons[] = $this->createElement('submitButton', 'delete', [
                'label' => $this->translate('Delete'),
                'data-progress-label' => $this->translate('Deleting rule'),
                'form' => 'event-rule-config-form',
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
        }

        return $buttons;
    }

    /**
     * Get whether the delete button was pressed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    public function hasBeenSubmitted(): bool
    {
        $pressedButton = $this->getPressedSubmitElement();

        if ($pressedButton && $pressedButton->getName() === 'save') {
            return true;
        }

        return false;
    }
}
