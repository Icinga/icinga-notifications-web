<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationCondition;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationRecipient;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Widget\FlowLine;
use Icinga\Module\Notifications\Widget\ItemList\Escalation;
use Icinga\Module\Notifications\Widget\ItemList\Escalations;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\Html;
use ipl\I18n\Translation;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EventRuleConfigFilter;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;

class EventRuleConfigForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    public const ON_DELETE = 'delete';

    public const ON_DISCARD = 'discard';

    public const ON_CHANGE = 'change';

    protected $defaultAttributes = [
        'class' => ['event-rule-config', 'icinga-form', 'icinga-controls'],
        'name'  => 'event-rule-config-form',
        'id'    => 'event-rule-config-form'
    ];

    /** @var array<string, mixed> */
    protected $config;

    /** @var Url */
    protected $searchEditorUrl;

    /**
     * Create a new EventRuleConfigForm
     *
     * @param array<string, mixed> $config
     * @param Url $searchEditorUrl
     */
    public function __construct(array $config, Url $searchEditorUrl)
    {
        $this->config = $config;
        $this->searchEditorUrl = $searchEditorUrl;
        $this->on(self::ON_SENT, function () {
            $config = array_merge($this->config, $this->getValues());

            if ($config !== $this->config) {
                $this->emit(self::ON_CHANGE, [$this]);
            }
        });
    }

    public function hasBeenSubmitted()
    {
        $pressedButton = $this->getPressedSubmitElement();

        if ($pressedButton) {
            $buttonName = $pressedButton->getName();

            if ($buttonName === 'delete') {
                $this->emit(self::ON_DELETE, [$this]);
            } elseif ($buttonName === 'discard_changes') {
                $this->emit(self::ON_DISCARD, [$this]);
            } elseif ($buttonName === 'save') {
                return true;
            }
        }

        return false;
    }

    protected function generateFakeEscalationId(): string
    {
        return bin2hex(random_bytes(4));
    }

    protected function assemble(): void
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        // Replicate save button outside the form
        $this->addElement(
            'submitButton',
            'save',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        // Replicate delete button outside the form
        $this->addElement(
            'submitButton',
            'delete',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        // Replicate discard_changes button outside the form
        $this->addElement(
            'submitButton',
            'discard_changes',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        /** @var string $ruleId */
        $ruleId = $this->config['id'];
        if ($ruleId === '-1') {
            $initialZeroConditionEscalation = bin2hex('1');
        } else {
            $initialZeroConditionEscalation = null;
        }

        $this->addElement(
            'hidden',
            'zero-condition-escalation',
            ['value' => $initialZeroConditionEscalation]
        );

        /** @var string $objectFilter */
        $objectFilter = $this->config['object_filter'] ?? '';
        $configFilter = (new EventRuleConfigFilter('config-filter'))
            ->setObjectFilter($objectFilter)
            ->setSearchEditorUrl($this->searchEditorUrl);
        $this->registerElement($configFilter);

        $addEscalationButton = $this->createElement(
            'submitButton',
            'add-escalation',
            [
                'class' => ['add-button', 'control-button', 'spinner'],
                'label' => new Icon('plus'),
                'title' => $this->translate('Add Escalation'),
                'formnovalidate' => true
            ]
        );

        $this->registerElement($addEscalationButton);

        $prefixesElement = $this->createElement(
            'hidden',
            'prefixes-map',
            ['value' => $initialZeroConditionEscalation]
        );

        $this->addElement($prefixesElement);
        $this->handleAdd();

        /** @var string $prefixesMapString */
        $prefixesMapString = $prefixesElement->getValue();

        /** @var array<int, string> $prefixesMap */
        $prefixesMap = explode(',', $prefixesMapString);
        $escalationCount = count($prefixesMap);

        foreach ($prefixesMap as $prefixMap) {
            if ($escalationCount === 1) {
                continue;
            }

            $removeEscalationButtons[$prefixMap] = $this->createRemoveButton($prefixMap);
        }

        /** @var ?string $removePosition */
        $removePosition = $this->getValue('remove-escalation');
        if ($removePosition) {
            if ($escalationCount === 2) {
                $removeEscalationButtons = [];
            }
        }

        /** @var ?string $zeroConditionEscalation */
        $zeroConditionEscalation = $this->getValue('zero-condition-escalation');

        if ($zeroConditionEscalation === null) {
            $noZeroEscalationConditions = true;
        } else {
            $noZeroEscalationConditions = false;
        }

        $escalations = [];

        $i = 1;
        foreach ($prefixesMap as $key => $prefixMap) {
            if ($removePosition === $prefixMap) {
                if ($this->getValue('zero-condition-escalation') === $prefixMap) {
                    $this->getElement('zero-condition-escalation')->setValue(null);
                }

                unset($prefixesMap[$key]);
                $this->getElement('prefixes-map')->setValue(implode(',', $prefixesMap));
                continue;
            }

            $escalationCondition = new EscalationCondition(
                'escalation-condition_' . $prefixMap
            );

            $escalationRecipient = new EscalationRecipient(
                'escalation-recipient_' . $prefixMap
            );

            $this->registerElement($escalationCondition);
            $this->registerElement($escalationRecipient);

            $escalation = new Escalation(
                $escalationCondition,
                $escalationRecipient,
                $removeEscalationButtons[$prefixMap] ?? null
            );

            if (
                $this->getValue('zero-condition-escalation') === $prefixMap
                && $escalation->addConditionHasBeenPressed()
            ) {
                $noZeroEscalationConditions = true;
                $this->clearPopulatedValue('zero-condition-escalation');
            } elseif (
                $zeroConditionEscalation === null
                && $escalation->lastConditionHasBeenRemoved()
            ) {
                $noZeroEscalationConditions = false;
                $this->clearPopulatedValue('zero-condition-escalation');
                $this->getElement('zero-condition-escalation')->setValue($prefixMap);
            }

            $escalations[$prefixMap] = $escalation;
            $i++;
        }

        if ($noZeroEscalationConditions === false) {
            $noZeroEscalationConditions = $this->getValue('zero-condition-escalation') === null;
        }

        $this->add(Html::tag(
            'ul',
            ['class' => 'filter-wrapper'],
            [
                Html::tag('li', (new FlowLine())->getRightArrow()),
                Html::tag('li', $configFilter),
                Html::tag('li', (new FlowLine())->getHorizontalLine())
            ]
        ));

        if ($noZeroEscalationConditions === true) {
            foreach ($escalations as $escalation) {
                $escalation->getCondition()
                    ->setAllowZeroConditions(true);
            }

            $this->getElement('zero-condition-escalation')
                ->setValue(null);
        } else {
            /** @var string $zeroConditionPosition */
            $zeroConditionPosition = $this->getValue('zero-condition-escalation');
            if ($zeroConditionPosition) {
                $escalations[$zeroConditionPosition]
                    ->getCondition()
                    ->setAllowZeroConditions(true);
            }
        }

        $this->add(new Escalations($escalations, $addEscalationButton));
    }

    /**
     * Handle addition of escalations
     */
    protected function handleAdd(): void
    {
        $pressedButton = $this->getPressedSubmitElement();

        if ($pressedButton && $pressedButton->getName() === 'add-escalation') {
            $this->clearPopulatedValue('prefixes-map');
            /** @var string $prefixesMapString */
            $prefixesMapString = $this->getValue('prefixes-map');
            $prefixesMap = explode(',', $prefixesMapString);
            $escalationFakePos = bin2hex(random_bytes(4));
            $prefixesMap[] = $escalationFakePos;
            $this->getElement('prefixes-map')
                ->setValue(implode(',', $prefixesMap));

            if ($this->getValue('zero-condition-escalation') === null) {
                $this->getElement('zero-condition-escalation')
                    ->setValue($escalationFakePos);
            }
        }
    }

    public function populate($values): self
    {
        if (! isset($values['rule_escalation'])) {
            return parent::populate($values);
        }

        $formValues = [];
        $formValues['prefixes-map'] = $this->getPrefixesMap(count($values['rule_escalation']));

        foreach ($values['rule_escalation'] as $position => $escalation) {
            $conditions = explode('|', $escalation['condition'] ?? '');
            $conditionFormValues = [];
            $conditionFormValues['condition-count'] = count($conditions);
            $conditionFormValues['id'] = $escalation['id'] ?? bin2hex(random_bytes(4));
            foreach ($conditions as $key => $condition) {
                if ($condition === '') {
                    if (! isset($formValues['zero-condition-escalation'])) {
                        $formValues['zero-condition-escalation'] = bin2hex($position);
                    }

                    $conditionFormValues['condition-count'] = 0;
                    continue;
                }

                $count = $key + 1;
                if (empty($condition)) {
                    // when other conditions are removed and only 1 pending with no values
                    $conditionFormValues['column_' . $count] = null;
                    $conditionFormValues['operator_' . $count] = null;
                    $conditionFormValues['value_' . $count] = null;

                    continue;
                }

                /** @var Condition $filter */
                $filter = QueryString::parse($condition);
                $conditionFormValues['column_' . $count] = $filter->getColumn() === 'placeholder'
                    ? null
                    : $filter->getColumn();

                if ($conditionFormValues['column_' . $count]) {
                    $conditionFormValues['type_' . $count] = $conditionFormValues['column_' . $count];
                }

                $conditionFormValues['operator_' . $count] = QueryString::getRuleSymbol($filter);
                $conditionFormValues['val_' . $count] = $filter->getValue();
            }

            $formValues['escalation-condition_' . bin2hex($position)] =  $conditionFormValues;
            $recipientFormValues = [];
            if (isset($escalation['recipients'])) {
                $recipientFormValues['recipient-count'] = count($escalation['recipients']);
                foreach ($escalation['recipients'] as $key => $recipient) {
                    if (is_array($recipient)) {
                        $count = 0;
                        foreach ($recipient as $elementName => $elementValue) {
                            if ($elementValue === null) {
                                continue;
                            }

                            $count = $key + 1;
                            $selectedOption = str_replace('id', $elementValue, $elementName, $replaced);
                            if ($replaced && $elementName !== 'channel_id') {
                                $recipientFormValues['column_' . $count] = $selectedOption;
                            } elseif ($elementName === 'channel_id') {
                                $recipientFormValues['val_' . $count] = $elementValue;
                            }
                        }

                        if (isset($recipient['id'])) {
                            $recipientFormValues['id_' . $count] = (int) $recipient['id'];
                        }
                    }
                }
            }

            $formValues['escalation-recipient_' . bin2hex($position)] =  $recipientFormValues;
        }

        return parent::populate($formValues);
    }

    /**
     * Get the values for the current EventRuleConfigForm
     *
     * @return array<string, mixed> values as name-value pairs
     */
    public function getValues(): array
    {
        $values = [];
        $escalations = [];
        /** @var string $prefixesString */
        $prefixesString = $this->getValue('prefixes-map');

        /** @var string[] $prefixesMap */
        $prefixesMap = explode(',', $prefixesString);
        $i = 1;
        foreach ($prefixesMap as $prefixMap) {
            /** @var EscalationCondition $escalationCondition */
            $escalationCondition = $this->getElement('escalation-condition_' . $prefixMap);
            /** @var EscalationRecipient $escalationRecipient */
            $escalationRecipient = $this->getElement('escalation-recipient_' . $prefixMap);
            $escalations[$i]['condition'] = $escalationCondition->getCondition();
            $escalations[$i]['id'] = $escalationCondition->getValue('id');
            $escalations[$i]['recipients'] = $escalationRecipient->getRecipients();
            $i++;
        }

        /** @var EventRuleConfigFilter $configFilter */
        $configFilter = $this->getElement('config-filter');
        $values['object_filter'] = $configFilter->getObjectFilter();
        $values['rule_escalation'] = $escalations;

        return $values;
    }

    /**
     *  Create remove button for the given escalation position
     *
     * @param string $prefix
     *
     * @return SubmitButtonElement
     */
    protected function createRemoveButton(string $prefix): SubmitButtonElement
    {
        /** @var array<int, array<string, mixed>> $escalations */
        $escalations = $this->config['rule_escalation'] ?? [];

        $pos = hex2bin($prefix);
        $disableRemoveButton = false;
        if ($pos) {
            $escalationId = $escalations[$pos]['id'] ?? null;
            $incident = Incident::on(Database::get())
                ->with('rule_escalation');

            if ($escalationId && is_numeric($escalationId)) {
                $incident->filter(Filter::equal('rule_escalation.id', $escalationId));
                if ($incident->count() > 0) {
                    $disableRemoveButton = true;
                }
            }
        }

        /** @var SubmitButtonElement $button */
        $button = $this->createElement(
            'submitButton',
            'remove-escalation',
            [
                'class' => [
                    'remove-escalation',
                    'remove-button',
                    'control-button',
                    'spinner'
                ],
                'label' => new Icon('minus'),
                'formnovalidate' => true,
                'value' => $prefix
            ]
        );

        $button
            ->getAttributes()
            ->registerAttributeCallback('disabled', function () use ($disableRemoveButton) {
                return $disableRemoveButton;
            })
            ->registerAttributeCallback('title', function () use ($disableRemoveButton) {
                if ($disableRemoveButton) {
                    return $this->translate(
                        'There exist active incidents for this escalation and hence cannot be removed'
                    );
                }

                return $this->translate('Remove escalation');
            });

        $this->registerElement($button);

        return $button;
    }

    /**
     * Insert to or update event rule in the database
     *
     * @param string $id The id of the event rule
     * @param array<string, mixed> $config The new configuration
     */
    public function addOrUpdateRule(string $id, array $config): void
    {
        $db = Database::get();

        $db->beginTransaction();

        if ($id < 0) {
            $db->insert('rule', [
                'name' => $config['name'],
                'timeperiod_id' => $config['timeperiod_id'] ?? null,
                'object_filter' => $config['object_filter'] ?? null,
                'is_active' => $config['is_active'] ?? 'n'
            ]);

            $id = $db->lastInsertId();
        } else {
            $db->update('rule', [
                'name' => $config['name'],
                'timeperiod_id' => $config['timeperiod_id'] ?? null,
                'object_filter' => $config['object_filter'] ?? null,
                'is_active' => $config['is_active'] ?? 'n'
            ], ['id = ?' => $id]);
        }

        $escalationsFromDb = RuleEscalation::on($db)
            ->filter(Filter::equal('rule_id', $id));

        /** @var array<int, array<string, mixed>> $escalationsInCache */
        $escalationsInCache = $config['rule_escalation'];

        $escalationsToUpdate = [];
        $escalationsToRemove = [];

        /** @var RuleEscalation $escalationFromDB */
        foreach ($escalationsFromDb as $escalationFromDB) {
            $escalationId = $escalationFromDB->id;
            $escalationInCache = array_filter($escalationsInCache, function (array $element) use ($escalationId) {
                /** @var string $idInCache */
                $idInCache = $element['id'] ?? null;
                return (int) $idInCache === $escalationId;
            });

            if ($escalationInCache) {
                $position = array_key_first($escalationInCache);
                // Escalations in DB to update
                $escalationsToUpdate[$position] = $escalationInCache[$position];
                unset($escalationsInCache[$position]);
            } else {
                // Escalation in DB to remove
                $escalationsToRemove[] = $escalationId;
            }
        }

        // Escalations to add
        $escalationsToAdd = $escalationsInCache;

        if (! empty($escalationsToRemove)) {
            $db->delete('rule_escalation_recipient', ['rule_escalation_id IN (?)' => $escalationsToRemove]);
            $db->delete('rule_escalation', ['id IN (?)' => $escalationsToRemove]);
        }

        if (! empty($escalationsToAdd)) {
            $this->insertOrUpdateEscalations((int) $id, $escalationsToAdd, $db, true);
        }

        if (! empty($escalationsToUpdate)) {
            $this->insertOrUpdateEscalations((int) $id, $escalationsToUpdate, $db);
        }

        $db->commitTransaction();
    }

    /**
     * Insert to or update escalations in Db
     *
     * @param array<int, array<string, mixed>> $escalations
     * @param Connection $db
     * @param bool $insert
     */
    private function insertOrUpdateEscalations(
        int $ruleId,
        array $escalations,
        Connection $db,
        bool $insert = false
    ): void {
        foreach ($escalations as $position => $escalationConfig) {
            /** @var array<int, array<string, mixed>> $recipientsFromConfig */
            $recipientsFromConfig = $escalationConfig['recipients'] ?? [];
            if ($insert) {
                $db->insert('rule_escalation', [
                    'rule_id' => $ruleId,
                    'position' => $position,
                    'condition' => $escalationConfig['condition'] ?? null,
                    'name' => $escalationConfig['name'] ?? null,
                    'fallback_for' => $escalationConfig['fallback_for'] ?? null
                ]);

                /** @var string $escalationId */
                $escalationId = $db->lastInsertId();
            } else {
                /** @var string $escalationId */
                $escalationId = $escalationConfig['id'];

                $db->update('rule_escalation', [
                    'position' => $position,
                    'condition' => $escalationConfig['condition'] ?? null,
                    'name' => $escalationConfig['name'] ?? null,
                    'fallback_for' => $escalationConfig['fallback_for'] ?? null
                ], ['id = ?' => $escalationId, 'rule_id = ?' => $ruleId]);
                $recipientsToRemove = [];

                $recipients = RuleEscalationRecipient::on($db)
                    ->columns('id')
                    ->filter(Filter::equal('rule_escalation_id', $escalationId));

                /** @var RuleEscalationRecipient $recipient */
                foreach ($recipients as $recipient) {
                    $recipientId = $recipient->id;
                    $recipientInCache = array_filter(
                        $recipientsFromConfig,
                        function (array $element) use ($recipientId) {
                            /** @var string $idFromCache */
                            $idFromCache = $element['id'];
                            return (int) $idFromCache === $recipientId;
                        }
                    );

                    if (empty($recipientInCache)) {
                        // Recipients to remove from Db not in cache
                        $recipientsToRemove[] = $recipientId;
                    }
                }

                if (! empty($recipientsToRemove)) {
                    $db->delete('rule_escalation_recipient', ['id IN (?)' => $recipientsToRemove]);
                }
            }

            /** @var array<string, mixed> $recipientConfig */
            foreach ($recipientsFromConfig as $recipientConfig) {
                $data = [
                    'rule_escalation_id' => $escalationId,
                    'channel_id' => $recipientConfig['channel_id']
                ];

                switch (true) {
                    case isset($recipientConfig['contact_id']):
                        $data['contact_id'] = $recipientConfig['contact_id'];
                        $data['contactgroup_id'] = null;
                        $data['schedule_id'] = null;
                        break;
                    case isset($recipientConfig['contactgroup_id']):
                        $data['contact_id'] = null;
                        $data['contactgroup_id'] = $recipientConfig['contactgroup_id'];
                        $data['schedule_id'] = null;
                        break;
                    case isset($recipientConfig['schedule_id']):
                        $data['contact_id'] = null;
                        $data['contactgroup_id'] = null;
                        $data['schedule_id'] = $recipientConfig['schedule_id'];
                        break;
                }

                if (! isset($recipientConfig['id'])) {
                    $db->insert('rule_escalation_recipient', $data);
                } else {
                    $db->update('rule_escalation_recipient', $data, ['id = ?' => $recipientConfig['id']]);
                }
            }
        }
    }

    public function isValidEvent($event)
    {
        if (in_array($event, [self::ON_CHANGE, self::ON_DELETE, self::ON_DISCARD])) {
            return true;
        }

        return parent::isValidEvent($event);
    }

    /**
     * Remove the given event rule
     *
     * @param int $id
     */
    public function removeRule(int $id): void
    {
        $db = Database::get();

        $db->beginTransaction();

        $escalations = RuleEscalation::on($db)
            ->columns('id')
            ->filter(Filter::equal('rule_id', $id));

        $escalationsToRemove = [];
        /** @var RuleEscalation $escalation */
        foreach ($escalations as $escalation) {
            $escalationsToRemove[] = $escalation->id;
        }

        if (! empty($escalationsToRemove)) {
            $db->delete('rule_escalation_recipient', ['rule_escalation_id IN (?)' => $escalationsToRemove]);
        }

        $db->delete('rule_escalation', ['rule_id = ?' => $id]);
        $db->delete('rule', ['id = ?' => $id]);

        $db->commitTransaction();
    }

    /**
     * Get the prefix map
     *
     * @param int $escalationCount
     *
     * @return string
     */
    protected function getPrefixesMap(int $escalationCount): string
    {
        $prefixesMap = [];
        for ($i = 1; $i <= $escalationCount; $i++) {
            $prefixesMap[(string) $i] = bin2hex((string) $i);
        }

        return implode(',', $prefixesMap);
    }
}
