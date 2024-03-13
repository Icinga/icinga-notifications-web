<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Web\FilterRenderer;
use Icinga\Module\Notifications\Web\Form\EventRuleDecorator;
use Icinga\Module\Notifications\Widget\ItemList\EscalationConditionList;
use Icinga\Module\Notifications\Widget\ItemList\EscalationConditionListItem;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

class EscalationCondition extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-condition'];

    /** @var EscalationConditionListItem[] Condition list */
    protected $conditions = [];

    /** @var bool Whether zero conditions allowed */
    public $allowZeroConditions;

    /** @var int Number of conditions */
    public $count = 0;

    /**
     * Set whether the zero conditions is allowed for the escalation
     *
     * @param bool $allowZeroConditions
     *
     * @return $this
     */
    public function setAllowZeroConditions(bool $allowZeroConditions): self
    {
        $this->allowZeroConditions = $allowZeroConditions;

        return $this;
    }

    protected function assemble(): void
    {
        if ($this->allowZeroConditions) {
            $defaultCount = 0;
        } else {
            $defaultCount = 1;
        }

        $this->addElement(
            'hidden',
            'condition-count',
            ['value' => (string) $defaultCount]
        );

        /** @var SubmitButtonElement $addCondition */
        $addCondition = $this->createElement(
            'submitButton',
            'add-condition',
            [
                'class'          => ['add-button', 'control-button', 'spinner'],
                'label'          => new Icon('plus'),
                'title'          => $this->translate('Add Condition'),
                'formnovalidate' => true
            ]
        );

        $this->registerElement($addCondition);

        /** @var string|int $conditionCount */
        $conditionCount = $this->getValue('condition-count');
        $conditionCount = (int) $conditionCount;
        $this->addElement(
            'hidden',
            'id'
        );

        if ($addCondition->hasBeenPressed()) {
            $conditionCount = $conditionCount + 1;
            $this->getElement('condition-count')->setValue($conditionCount);
        }

        for ($i = 1; $i <= $conditionCount; $i++) {
            $colName = 'column_' . $i;
            $opName = 'operator_' . $i;
            $typeName = 'type_' . $i;
            $valName = 'val_' . $i;

            /** @var BaseFormElement $col */
            $col = $this->createElement(
                'select',
                $colName,
                [
                    'class'           => ['autosubmit', 'left-operand'],
                    'options'         => [
                        ''                  => sprintf(' - %s - ', $this->translate('Please choose')),
                        'incident_severity' => $this->translate('Incident Severity'),
                        'incident_age'      => $this->translate('Incident Age')
                    ],
                    'disabledOptions' => [''],
                    'required'        => true
                ]
            );

            $operators = ['=', '>', '>=', '<', '<=', '!='];
            /** @var BaseFormElement $op */
            $op = $this->createElement(
                'select',
                $opName,
                [
                    'class'    => ['class' => 'operator-input', 'autosubmit'],
                    'options'  => array_combine($operators, $operators),
                    'required' => true
                ]
            );

            switch ($this->getPopulatedValue('column_' . $i)) {
                case 'incident_severity':
                    /** @var BaseFormElement $val */
                    $val = $this->createElement(
                        'select',
                        $valName,
                        [
                            'class'   => ['autosubmit', 'right-operand'],
                            'options' => [
                                'ok'      => $this->translate('Ok', 'notification.severity'),
                                'debug'   => $this->translate('Debug', 'notification.severity'),
                                'info'    => $this->translate('Information', 'notification.severity'),
                                'notice'  => $this->translate('Notice', 'notification.severity'),
                                'warning' => $this->translate('Warning', 'notification.severity'),
                                'err'     => $this->translate('Error', 'notification.severity'),
                                'crit'    => $this->translate('Critical', 'notification.severity'),
                                'alert'   => $this->translate('Alert', 'notification.severity'),
                                'emerg'   => $this->translate('Emergency', 'notification.severity')
                            ]
                        ]
                    );

                    if (
                        $this->getPopulatedValue($typeName) !== 'incident_severity'
                        && $this->getPopulatedValue($valName) !== null
                    ) {
                        $this->clearPopulatedValue($typeName);
                        $this->clearPopulatedValue($valName);
                    }

                    $this->addElement('hidden', $typeName, [
                        'value' => 'incident_severity'
                    ]);

                    break;
                case 'incident_age':
                    /** @var BaseFormElement $val */
                    $val = $this->createElement(
                        'text',
                        $valName,
                        [
                            'required'   => true,
                            'class'      => ['autosubmit', 'right-operand'],
                            'validators' => [
                                new CallbackValidator(function ($value, $validator) {
                                    if (! preg_match('~^\d+(?:\.?\d*)?[hms]{1}$~', $value)) {
                                        $validator->addMessage(
                                            $this->translate(
                                                'Only numbers with optional fractions (separated by a dot)'
                                                . ' and one of these suffixes are allowed: h, m, s'
                                            )
                                        );

                                        return false;
                                    }

                                    $validator->clearMessages();
                                    return true;
                                })
                            ]
                        ]
                    );

                    if (
                        $this->getPopulatedValue($typeName) !== 'incident_age'
                        && $this->getPopulatedValue($valName) !== null
                    ) {
                        $this->clearPopulatedValue($typeName);
                        $this->clearPopulatedValue($valName);
                    }

                    $this->addElement('hidden', $typeName, [
                        'value' => 'incident_age'
                    ]);

                    break;
                default:
                    /** @var BaseFormElement $val */
                    $val = $this->createElement('text', $valName, [
                        'class'       => 'right-operand',
                        'placeholder' => $this->translate('Please make a decision'),
                        'disabled'    => true
                    ]);
            }

            $this->registerElement($col);
            $this->registerElement($op);
            $this->registerElement($val);

            (new EventRuleDecorator())->decorate($val);
            /** @var ?SubmitButtonElement $removeButton */
            $removeButton = $this->createRemoveButton($i);

            $this->conditions[$i] = new EscalationConditionListItem(
                $col,
                $op,
                $val,
                $removeButton
            );
        }

        /** @var string $removePosition */
        $removePosition = $this->getValue('remove');
        if ($removePosition) {
            unset($this->conditions[$removePosition]);
            $conditionCount -= 1;
            if ($conditionCount === 1 && ! $this->allowZeroConditions && $removePosition === '2') {
                $this->conditions[1]->removeButton = null;
            } else {
                for ($n = (int) $removePosition; $n <= $conditionCount; $n++) {
                    $nextCount = $n + 1;
                    $this->conditions[$nextCount]->conditionType->setName('column_' . $n);
                    $this->conditions[$nextCount]->operator->setName('operator_' . $n);
                    $this->conditions[$nextCount]->conditionVal->setName('val_' . $n);
                    if ($conditionCount === 1) {
                        $this->conditions[$nextCount]->removeButton = null;
                    } elseif ($this->conditions[$nextCount]->removeButton) {
                        $this->conditions[$nextCount]->removeButton->setValue((string) $n);
                    }
                }
            }
            $this->getElement('condition-count')->setValue($conditionCount);
        }

        if ((int) $conditionCount === 0) {
            $this->addAttributes(['class' => ['zero-escalation-condition']]);
        } elseif ($this->getAttributes()) {
            $this->getAttributes()->remove('class', 'zero-escalation-condition');
        }

        $this->add(new EscalationConditionList($this->conditions));

        $this->addElement($addCondition);
    }

    /**
     * Create remove button for the condition in the given position
     *
     * @param int $count
     *
     * @return ?SubmitButtonElement
     */
    protected function createRemoveButton(int $count): ?SubmitButtonElement
    {
        // check for count and if allow zero conditions
        /** @var string|int $conditionCount */
        $conditionCount = $this->getValue('condition-count');
        if ((int) $conditionCount === 1 && ! $this->allowZeroConditions) {
            return null;
        }

        /** @var SubmitButtonElement $removeButton */
        $removeButton = $this->createElement(
            'submitButton',
            'remove',
            [
                'class'          => ['remove-button', 'control-button', 'spinner'],
                'label'          => new Icon('minus'),
                'title'          => $this->translate('Remove'),
                'formnovalidate' => true,
                'value'          => (string) $count
            ]
        );

        $this->registerElement($removeButton);

        return $removeButton;
    }

    public function hasValue(): bool
    {
        $this->ensureAssembled();
        return parent::hasValue();
    }

    /**
     * Get the rendered condition
     *
     * @return string
     */
    public function getCondition(): string
    {
        $filter = Filter::any();
        /** @var int $count */
        $count = $this->getValue('condition-count');

        if ($count > 0) { // if count is 0, loop runs in reverse direction
            foreach (range(1, $count) as $count) {
                $chosenType = $this->getValue('column_' . $count, 'placeholder');

                $filterStr = $chosenType
                    . $this->getValue('operator_' . $count)
                    . ($this->getValue('val_' . $count) ?? ($chosenType === 'incident_severity' ? 'ok' : ''));

                $filter->add(QueryString::parse($filterStr));
            }
        }

        return (new FilterRenderer($filter))
            ->render();
    }
}
