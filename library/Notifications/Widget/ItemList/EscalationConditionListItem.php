<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\SubmitButtonElement;

/**
 * Escalation condition item of an escalation condition list. Represents one condition.
 */
class EscalationConditionListItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalation-condition-list-item'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button for the recipient */
    protected $removeButton;

    /** @var FormElement Condition type */
    protected $conditionType;

    /** @var FormElement Operator used for the condition */
    protected $operator;

    /** @var FormElement Condition value */
    protected $conditionVal;

    /** @var int Position of the condition in the condition list */
    protected $position;

    /**
     * Create the condition list item of the escalation
     *
     * @param FormElement $conditionType
     * @param FormElement $operator
     * @param FormElement $conditionVal
     * @param ?SubmitButtonElement $removeButton
     */
    public function __construct(
        int $position,
        FormElement $conditionType,
        FormElement $operator,
        FormElement $conditionVal,
        ?SubmitButtonElement $removeButton
    ) {
        $this->position = $position;
        $this->conditionType = $conditionType;
        $this->operator = $operator;
        $this->conditionVal = $conditionVal;
        $this->removeButton = $removeButton;
    }

    /**
     * Return whether the condition has been removed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        return $this->removeButton && $this->removeButton->hasBeenPressed();
    }

    /**
     * Set the position of the condition list item
     *
     * @param int $position
     *
     * @return $this
     */
    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Removes the remove button from the list item
     *
     * @return $this
     */
    public function removeRemoveButton(): self
    {
        $this->removeButton = null;

        return $this;
    }

    protected function assemble(): void
    {
        $this->conditionType->setAttribute('name', 'column_' . $this->position);
        $this->operator->setAttribute('name', 'operator_' . $this->position);
        $this->conditionVal->setAttribute('name', 'val_' . $this->position);

        $this->addHtml($this->conditionType, $this->operator, $this->conditionVal);
        if ($this->removeButton) {
            $this->removeButton->setSubmitValue((string) $this->position);
            $this->addHtml($this->removeButton);
        }
    }
}
