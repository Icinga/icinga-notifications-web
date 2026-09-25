<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;
use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Form\Data\RuleEntry as RuleEntryData;
use Icinga\Module\Notifications\Forms\RuleEntryForm\EscalationConditions;
use Icinga\Module\Notifications\Forms\RuleEntryForm\RuleEntryRecipients;
use Icinga\Module\Notifications\Forms\RuleEntryForm\EventTypes;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Util\RuleSerializer;
use ipl\Html\Attributes;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use JsonException;
use UnexpectedValueException;

class RuleEntryForm extends CompatForm
{
    use CsrfCounterMeasure;

    public const ESCALATION_RULE = 'escalation';

    public const NOTIFICATION_RULE = 'notification';

    protected $defaultAttributes = [
        'class' => ['escalation-form'],
    ];

    /**
     * @param ConfigProviderInterface $configProvider
     * @param 'escalation'|'notification' $ruleType
     * @param ?string $submitButtonLabel Defaults to "Save Changes"
     */
    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly string $ruleType,
        private readonly ?string $submitButtonLabel = null
    ) {
        $this->addElementLoader('Icinga\\Module\\Notifications\\Forms\\RuleEntryForm');
        $this->applyDefaultElementDecorators();
    }

    /**
     * Load the given rule entry into the form
     *
     * @param RuleEntry $escalation
     *
     * @return $this
     */
    public function setEntry(RuleEntry $escalation): static
    {
        /** @var class-string<EscalationConditions|EventTypes> $conditionElementClass */
        $conditionElementClass = $this->loadPlugin('element', match ($this->ruleType) {
            self::ESCALATION_RULE => 'escalationConditions',
            self::NOTIFICATION_RULE => 'eventTypes'
        });

        try {
            $condition = RuleSerializer::decode($escalation->condition ?? '');
        } catch (JsonException $e) {
            Logger::error('Failed to parse rule entry condition: %s (Error: %s)', $escalation->condition, $e);
            throw new ConfigurationError($this->translate(
                'Failed to parse rule entry condition. Please contact your system administrator.'
            ));
        } catch (UnexpectedValueException $e) {
            Logger::error('Cannot load condition for rule entry with id %d: %s', $escalation->id, $e->getMessage());
            throw new ConfigurationError($this->translate(
                'Unsupported rule entry condition version. Please contact your system administrator.'
            ));
        }

        if (
            ($condition['assisted'] ?? false)
            && $this->configProvider->locateSourceHookByRuleId($escalation->rule_id) === null
        ) {
            throw new ConfigurationError($this->translate(
                'The rule entry condition was created with a source integration'
                . ' that is no longer available. Please contact your system administrator.'
            ));
        }

        $this->populate([
            'id' => $escalation->id,
            'rule_id' => $escalation->rule_id,
            'position' => $escalation->position,
            'condition' => $conditionElementClass::prepare($condition['qs'] ?? ''),
            'recipients' => RuleEntryRecipients::prepare(
                $escalation->rule_entry_recipient
                    ->columns(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ),
        ]);

        return $this;
    }

    /**
     * Get the rule entry as currently configured by the user
     *
     * @return RuleEntryData
     */
    public function getEntry(): RuleEntryData
    {
        $escalationId = null;
        if ($this->getElement('id')->hasValue()) {
            $escalationId = (int) $this->getElement('id')->getValue();
        }

        $condition = null;
        if ($this->hasElement('condition')) {
            [$filter, $queryString] = $this->getElement('condition')->getCondition();
            $condition = (new RuleSerializer(
                $filter,
                $queryString,
                assisted: $this->configProvider->locateSourceHookByRuleId((int) $this->getValue('rule_id')) !== null
            ))->getJson();
        }

        return new RuleEntryData(
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

        if ($this->ruleType === self::ESCALATION_RULE) {
            if ($position === 0) {
                $this->addHtml(
                    new HtmlElement(
                        'div',
                        Attributes::create(['class' => 'immediate-hint']),
                        Text::create($this->translate('Delivered immediately'))
                    )
                );
            } else {
                $this->addElement('escalationConditions', 'condition', [
                    'label' => $this->translate('Condition') . ' *',
                    'required' => true
                ]);
            }
        } else {
            $this->addElement('eventTypes', 'condition', [
                'label' => $this->translate('Event Types') . ' *',
                'hook' => $this->configProvider->locateSourceHookByRuleId((int) $this->getValue('rule_id')),
                'required' => true
            ]);
        }

        $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'connector'])));

        $this->addElement('ruleEntryRecipients', 'recipients', [
            'label' => $this->translate('Recipients') . ' *',
            'provider' => $this->configProvider,
            'required' => true,
        ]);

        $this->addElement('submit', 'btn_submit', [
            'label' => $this->submitButtonLabel ?? $this->translate('Save Changes')
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
