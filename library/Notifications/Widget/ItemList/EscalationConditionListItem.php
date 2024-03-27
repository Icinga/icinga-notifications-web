<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\SubmitButtonElement;

class EscalationConditionListItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'option'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button for the recipient */
    public $removeButton;

    /** @var BaseFormElement Condition type */
    public $conditionType;

    /** @var BaseFormElement Operator used for the condition */
    public $operator;

    /** @var BaseFormElement Condition value */
    public $conditionVal;

    public function __construct(
        BaseFormElement $conditionType,
        BaseFormElement $operator,
        BaseFormElement $conditionVal,
        ?SubmitButtonElement $removeButton
    ) {
        $this->conditionType = $conditionType;
        $this->operator = $operator;
        $this->conditionVal = $conditionVal;
        $this->removeButton = $removeButton;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->conditionType,
            $this->operator,
            $this->conditionVal,
            $this->removeButton
        ]);
    }
}
