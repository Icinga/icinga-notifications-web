<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\SubmitButtonElement;

class EscalationConditionListItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'option'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button for the recipient */
    protected $removeButton;

    /** @var FormElement Condition type */
    protected $conditionType;

    /** @var FormElement Operator used for the condition */
    protected $operator;

    /** @var FormElement Condition value */
    protected $conditionVal;

    /** @var int */
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

    public function setRemoveButton(?SubmitButtonElement $removeButton): self
    {
        $this->removeButton = $removeButton;

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
