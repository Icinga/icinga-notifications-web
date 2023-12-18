<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationCondition;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationRecipient;
use Icinga\Module\Notifications\Widget\FlowLine;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\SubmitButtonElement;

class Escalation extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalation'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button of the escalation widget */
    public $removeButton;

    /** @var EscalationCondition Escalation condition fieldset */
    public $condition;

    /** @var EscalationRecipient Escalation recipient fieldset */
    public $recipient;

    /** @var bool Whether the widget has a remove button */
    private $hasNoRemoveButton = false;

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
     * Get escalation condition
     *
     * @return EscalationCondition
     */
    public function getCondition(): EscalationCondition
    {
        return $this->condition;
    }

    /**
     * Check if the add button of the condition fieldset has been pressed
     *
     * @return bool
     */
    public function addConditionHasBeenPressed(): bool
    {
        return $this->getCondition()->getPopulatedValue('add-condition') === 'y';
    }

    /**
     * Check if the last condition of the escalation has been removed
     *
     * @return bool
     */
    public function lastConditionHasBeenRemoved(): bool
    {
        return $this->getCondition()->getPopulatedValue('condition-count') === '1'
            && $this->getCondition()->getPopulatedValue('remove') === '1';
    }

    /**
     * Get escalation recipient
     *
     * @return EscalationRecipient
     */
    protected function getRecipient(): EscalationRecipient
    {
        return $this->recipient;
    }

    /**
     * Set if escalation has remove button
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setHasNoRemovedButton(bool $state): self
    {
        $this->hasNoRemoveButton = $state;

        return $this;
    }

    /**
     * Create first component of the escalation widget
     *
     * @return FlowLine|FormElement|null
     */
    protected function createFirstComponent()
    {
        if ($this->hasNoRemoveButton || $this->removeButton === null) {
            return (new FlowLine())->getHorizontalLine();
        }

        return $this->removeButton;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->createFirstComponent(),
            (new FlowLine())->getRightArrow(),
            $this->getCondition(),
            (new FlowLine())->getRightArrow(),
            $this->getRecipient()
        ]);
    }
}
