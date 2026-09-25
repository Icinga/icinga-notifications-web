<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\EscalationForm;

use Icinga\Module\Notifications\Util\RuleSerializer;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type ConditionValues from EscalationCondition
 */
class EscalationConditions extends FieldsetElement
{
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalation-conditions'];

    /**
     * Serialize the given conditions
     *
     * @param Filter\Rule $filter
     *
     * @return string
     */
    public static function serialize(Filter\Rule $filter): string
    {
        return (new RuleSerializer(
            $filter,
            ['incident_age' => ['incident_age'], 'incident_severity' => ['incident_severity']],
            false
        ))->getJson();
    }

    protected function createAddButton(): SubmitButtonElement
    {
        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Condition'),
            'label' => [
                new Icon('plus'),
                new HtmlElement('span', content: Text::create($this->translate('Add Condition')))
            ],
            'class' => ['add-button', 'animated', 'link-button']
        ]);

        $button->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'add-button-wrapper'])));

        return $button;
    }

    protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement
    {
        $condition = new EscalationCondition($no);
        if ($removeButton !== null) {
            $condition->setRemoveButton($removeButton);
        }

        return $condition;
    }

    /**
     * Prepare the conditions for display
     *
     * @param string $json The stored condition as JSON
     *
     * @return array<ConditionValues>
     */
    public static function prepare(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $filters = QueryString::parse(json_decode($json, true)['qs']);
        if ($filters instanceof Condition) {
            $filters = [$filters];
        }

        $conditions = [];
        foreach ($filters as $condition) {
            $conditions[] = EscalationCondition::prepare($condition);
        }

        return $conditions;
    }

    /**
     * Get the conditions to store
     *
     * @return ?string
     */
    public function getConditions(): ?string
    {
        $filters = Filter::all();
        foreach ($this->ensureAssembled()->getElements() as $element) {
            if ($element instanceof EscalationCondition) {
                $filters->add($element->getCondition());
            }
        }

        if ($filters->isEmpty()) {
            return null;
        }

        return static::serialize($filters);
    }
}
