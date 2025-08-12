<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Web\FilterRenderer;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type ConditionData from EscalationCondition
 */
class EscalationConditions extends FieldsetElement
{
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalation-conditions'];

    protected function createAddButton(): SubmitButtonElement
    {
        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Condition'),
            'label' => new Icon('plus'),
            'class' => ['add-button', 'animated']
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
     * @param string $query The query string
     *
     * @return array<ConditionData>
     */
    public static function prepare(string $query): array
    {
        $filters = QueryString::parse($query);
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

        return (new FilterRenderer($filters))->render();
    }
}
