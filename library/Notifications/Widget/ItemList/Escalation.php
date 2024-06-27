<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationCondition;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationRecipient;
use Icinga\Module\Notifications\Widget\FlowLine;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormElement\SubmitButtonElement;

class Escalation extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalation'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button of the escalation */
    protected $removeButton;

    /** @var EscalationCondition Escalation condition fieldset */
    protected $condition;

    /** @var EscalationRecipient Escalation recipient fieldset */
    protected $recipient;

    /**
     * Create the escalation list item
     *
     * @param EscalationCondition $condition
     * @param EscalationRecipient $recipient
     * @param ?SubmitButtonElement $removeButton
     */
    public function __construct(
        EscalationCondition $condition,
        EscalationRecipient $recipient,
        ?SubmitButtonElement $removeButton
    ) {
        $this->condition = $condition;
        $this->recipient = $recipient;
        $this->removeButton = $removeButton;
    }

    /**
     * Check if the add button of the condition fieldset has been pressed
     *
     * @return bool
     */
    public function addConditionHasBeenPressed(): bool
    {
        return $this->condition->getPopulatedValue('add-condition') === 'y';
    }

    /**
     * Check if the last condition of the escalation has been removed
     *
     * @return bool
     */
    public function lastConditionHasBeenRemoved(): bool
    {
        return $this->condition->getPopulatedValue('condition-count') === '1'
            && $this->condition->getPopulatedValue('remove') === '1';
    }

    /**
     * Create first component of the escalation widget
     *
     * @return FlowLine|SubmitButtonElement
     */
    protected function createFirstComponent()
    {
        if ($this->removeButton === null) {
            return (new FlowLine())->getHorizontalLine();
        }

        return $this->removeButton;
    }

    protected function assemble(): void
    {
        $firstComponent = $this->createFirstComponent();
        if ($firstComponent) {
            $this->addHtml($firstComponent);
        }

        $this->addHtml(
            (new FlowLine())->getRightArrow(),
            $this->condition,
            (new FlowLine())->getRightArrow(),
            $this->recipient
        );
    }
}
