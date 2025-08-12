<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Model\RuleEscalation;
use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type ConditionData from EscalationCondition
 * @phpstan-import-type RecipientType from EscalationRecipient
 * @phpstan-type EscalationType array{
 *     id: int|null,
 *     position: int,
 *     condition: string|null
 * }
 */
class Escalation extends FieldsetElement
{
    use ConfigProvider {
        registerAttributeCallbacks as protected baseRegisterAttributeCallbacks;
    }

    protected $defaultAttributes = ['class' => 'escalation'];

    /** @var ?SubmitButtonElement The button to remove this escalation */
    protected ?SubmitButtonElement $removeButton = null;

    /** @var bool Whether the escalation can be triggered immediately */
    protected bool $immediate = false;

    /**
     * Set the button to remove this escalation
     *
     * @param SubmitButtonElement $removeButton
     *
     * @return void
     */
    public function setRemoveButton(SubmitButtonElement $removeButton): void
    {
        $this->removeButton = $removeButton;
    }

    /**
     * Set whether the escalation can be triggered immediately
     *
     * @param bool $immediate
     *
     * @return void
     */
    public function setImmediate(bool $immediate): void
    {
        $this->immediate = $immediate;
    }

    /**
     * Prepare the escalation for display
     *
     * @param RuleEscalation $escalation
     *
     * @return array{id: int, conditions: array<ConditionData>, recipients: array<RecipientType>}
     */
    public static function prepare(RuleEscalation $escalation): array
    {
        return [
            'id' => $escalation->id,
            'conditions' => EscalationConditions::prepare($escalation->condition ?? ''),
            'recipients' => EscalationRecipients::prepare(
                $escalation->rule_escalation_recipient
                    ->columns(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ),
        ];
    }

    /**
     * Check whether the escalation position or conditions have changed, according to the given previous escalation
     *
     * @param RuleEscalation $previousEscalation
     *
     * @return bool
     */
    public function hasChanged(RuleEscalation $previousEscalation): bool
    {
        if ($previousEscalation->position !== (int) $this->getName()) {
            return true;
        }

        if ($previousEscalation->condition !== $this->getElement('conditions')->getConditions()) {
            return true;
        }

        return false;
    }

    /**
     * Get the escalation to store
     *
     * @return EscalationType
     */
    public function getEscalation(): array
    {
        $escalationId = null;
        if ($this->getElement('id')->hasValue()) {
            $escalationId = (int) $this->getElement('id')->getValue();
        }

        return [
            'id' => $escalationId,
            'position' => (int) $this->getName(),
            'condition' => $this->getElement('conditions')->getConditions()
        ];
    }

    /**
     * Get the escalation recipients
     *
     * @return array<EscalationRecipient>
     */
    public function getRecipients(): array
    {
        return $this->getElement('recipients')->getRecipients();
    }

    protected function assemble(): void
    {
        if ($this->removeButton !== null) {
            $this->addHtml(new HtmlElement(
                'div',
                null,
                $this->removeButton->setLabel(new Icon('minus'))
                    ->setAttribute('class', ['remove-button', 'animated'])
                    ->setAttribute('title', $this->translate('Remove Escalation'))
            ));
        } else {
            $this->addHtml(new HtmlElement(
                'div',
                null,
                new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
            ));
        }

        $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'connector-line'])));

        $this->addElement(
            (new EscalationConditions('conditions', ['required' => ! $this->immediate]))
                ->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'set-wrapper'])))
        );

        $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'connector-line'])));

        $this->addElement(
            (new EscalationRecipients('recipients', [
                'provider' => $this->provider,
                'required' => true
            ]))
                ->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'set-wrapper'])))
        );

        $this->addElement('hidden', 'id');
    }

    protected function registerAttributeCallbacks(Attributes $attributes): void
    {
        $attributes->registerAttributeCallback('immediate', null, $this->setImmediate(...));

        $this->baseRegisterAttributeCallbacks($attributes);
    }
}
