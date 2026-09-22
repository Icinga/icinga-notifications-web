<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Forms\EscalationForm\EscalationConditions;
use Icinga\Module\Notifications\Forms\EscalationForm\EscalationRecipients;
use Icinga\Module\Notifications\Model\RuleEscalation;
use ipl\Html\Attributes;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class EscalationForm extends CompatForm
{
    use CsrfCounterMeasure;

    protected $defaultAttributes = [
        'class' => ['escalation-form'],
    ];

    /**
     * @param ConfigProviderInterface $configProvider
     */
    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
    ) {
        $this->addElementLoader('Icinga\\Module\\Notifications\\Forms\\EscalationForm');
        $this->applyDefaultElementDecorators();
    }

    /**
     * Load the given escalation into the form
     *
     * @param RuleEscalation $escalation
     *
     * @return $this
     */
    public function setEscalation(RuleEscalation $escalation): static
    {
        $this->populate([
            'id' => $escalation->id,
            'rule_id' => $escalation->rule_id,
            'position' => $escalation->position,
            'conditions' => EscalationConditions::prepare($escalation->condition ?? ''),
            'recipients' => EscalationRecipients::prepare(
                $escalation->rule_escalation_recipient
                    ->columns(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ),
        ]);

        return $this;
    }

    /**
     * Get the escalation as currently configured by the user
     *
     * @return Escalation
     */
    public function getEscalation(): Escalation
    {
        $escalationId = null;
        if ($this->getElement('id')->hasValue()) {
            $escalationId = (int) $this->getElement('id')->getValue();
        }

        $condition = null;
        if ($this->hasElement('conditions')) {
            $condition = $this->getElement('conditions')->getConditions();
        }

        return new Escalation(
            $escalationId,
            (int) $this->getValue('position'),
            $condition,
            $this->getElement('recipients')->getRecipients(),
            (int) $this->getValue('rule_id')
        );
    }

    /**
     * Check if the delete button was pressed
     *
     * @return bool
     */
    public function hasBeenDeleted(): bool
    {
        $btn = $this->getPressedSubmitElement();

        return $btn !== null && $btn->getName() === 'delete';
    }

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure();

        $this->addElement('hidden', 'id');
        $escalationId = $this->getPopulatedValue('id') ?: null;
        if ($escalationId !== null) {
            $escalationId = (int) $escalationId;
        }

        $this->addElement('hidden', 'position', ['required' => true]);
        $position = $this->getPopulatedValue('position');
        if ($position !== null) {
            $position = (int) $position;
        }

        $this->addElement('hidden', 'rule_id', ['required' => true]);

        if ($position === 0) {
            $this->addHtml(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => 'immediate-hint']),
                    Text::create($this->translate('Delivered immediately'))
                )
            );
        } else {
            $this->addElement('escalationConditions', 'conditions', [
                'label' => $this->translate('Condition') . ' *',
                'required' => true
            ]);
        }

        $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'connector'])));

        $this->addElement('escalationRecipients', 'recipients', [
            'label' => $this->translate('Recipients') . ' *',
            'provider' => $this->configProvider,
            'required' => true,
        ]);

        $this->addElement('submit', 'btn_submit', [
            'label' => $escalationId === null
                ? $this->translate('Create Escalation')
                : $this->translate('Save Changes')
        ]);

        $primaryButtonWrapper = new HtmlElement('div', Attributes::create(['class' => 'icinga-controls']));
        $this->getElement('btn_submit')->prependWrapper($primaryButtonWrapper);

        if ($escalationId !== null) {
            $deleteBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);

            $this->registerElement($deleteBtn);
            $primaryButtonWrapper->addHtml($deleteBtn);
        }
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || ($this->hasBeenSent() && $this->hasBeenDeleted());
    }
}
