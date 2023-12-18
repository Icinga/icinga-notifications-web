<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Web\FilterRenderer;
use Icinga\Module\Notifications\Web\Form\EventRuleDecorator;
use Icinga\Module\Notifications\Widget\ItemList\EscalationConditionList;
use Icinga\Module\Notifications\Widget\ItemList\EscalationConditionListItem;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

class EscalationCondition extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-condition'];

    /** @var EscalationConditionListItem[] Condition list items */
    protected $conditionListItems = [];

    /** @var EventRuleConfigForm */
    protected $configForm;

    /** @var string */
    protected $prefix;

    /** @var string condition filter */
    protected $condition;

    public function __construct(string $prefix, EventRuleConfigForm $configForm)
    {
        $this->prefix = $prefix;
        $this->configForm = $configForm;

        parent::__construct('escalation-condition_' . $this->prefix);
    }

    /**
     * Set the condition value
     *
     * @param $id
     * @param $condition
     *
     * @return $this
     */
    public function setCondition($condition): self
    {
        $this->condition = $condition;

        return $this;
    }

    /**
     * Get the rendered condition
     *
     * @return string
     */
    public function getCondition(): string
    {
        return $this->condition;
    }

    protected function assemble(): void
    {
        $filters = QueryString::parse($this->condition);

        if ($filters instanceof Filter\Chain) {
            $conditionCount = $filters->count();
        } else {
            $conditionCount = 1;

            $filters = Filter::all($filters);
        }

        $this->addElement('hidden', 'condition-count', ['value' => $conditionCount]);
        // Escalation Id to which the condition belongs
        $this->addElement('hidden', 'id');

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

        $zeroConditions = (string) $this->configForm->getValue('zero-condition-escalation') === $this->prefix;
        $configHasZeroConditionEscalation = $this->configForm->hasZeroConditionEscalation();
        if ($zeroConditions && $configHasZeroConditionEscalation) {
            $conditionCount = 0;
        } elseif ($conditionCount === 0) {
            $filters->add(Filter::equal('placeholder', ''));

            $conditionCount += 1;
        }

        if ($addCondition->hasBeenPressed()) {
            $filters->add(Filter::equal('placeholder', ''));
            $conditionCount += 1;
            $this->getElement('condition-count')->setValue($conditionCount);
        }

        if ($conditionCount === 0) {
            $this->addAttributes(['class' => 'zero-escalation-condition']);
            $this->addElement($addCondition);

            return;
        }

        $this->getAttributes()->remove('class', 'zero-escalation-condition');
        $removePosition = null;

        $position = 1;
        $operators = ['=', '>', '>=', '<', '<=', '!='];
        $severityOptions = [
            'ok'      => $this->translate('Ok', 'notification.severity'),
            'debug'   => $this->translate('Debug', 'notification.severity'),
            'info'    => $this->translate('Information', 'notification.severity'),
            'notice'  => $this->translate('Notice', 'notification.severity'),
            'warning' => $this->translate('Warning', 'notification.severity'),
            'err'     => $this->translate('Error', 'notification.severity'),
            'crit'    => $this->translate('Critical', 'notification.severity'),
            'alert'   => $this->translate('Alert', 'notification.severity'),
            'emerg'   => $this->translate('Emergency', 'notification.severity')
        ];

        /** @var Filter\Condition $filter */
        foreach ($filters as $filter) {
            $filterType = $this->getPopulatedValue('column_' . $position) ?? $filter->getColumn();
            if ($filterType === 'placeholder') {
                $filterType = '';
            }

            $typeElement = $this->createElement(
                'select',
                'column_' . $position,
                [
                    'class'           => ['autosubmit', 'left-operand'],
                    'options'         => [
                        ''                  => sprintf(' - %s - ', $this->translate('Please choose')),
                        'incident_severity' => $this->translate('Incident Severity'),
                        'incident_age'      => $this->translate('Incident Age')
                    ],
                    'disabledOptions' => [''],
                    'required'        => true,
                    'value'           => $filterType,
                ]
            );

            $operatorElement = $this->createElement(
                'select',
                'operator_' . $position,
                [
                    'class'     => ['operator-input', 'autosubmit'],
                    'options'   => array_combine($operators, $operators),
                    'required'  => true,
                    'value'     => QueryString::getRuleSymbol($filter),
                ]
            );

            $valName = 'val_' . $position;
            $filterValue = $filter->getValue();
            switch ($filterType) {
                case 'incident_severity':
                    $valElement = $this->createElement(
                        'select',
                        $valName,
                        [
                            'class'   => ['autosubmit', 'right-operand'],
                            'options' => $severityOptions,
                            'value'   => $filterValue
                        ]
                    );

                    break;
                case 'incident_age':
                    if (array_key_exists($filterValue, $severityOptions)) {
                        $filterValue = '';
                        $this->clearPopulatedValue($valName);
                    }

                    $valElement = $this->createElement(
                        'text',
                        $valName,
                        [
                            'required'   => true,
                            'class'      => ['autosubmit', 'right-operand'],
                            'value'       => $filterValue,
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

                    break;
                default:
                    $valElement = $this->createElement('text', $valName, [
                        'class'       => 'right-operand',
                        'placeholder' => $this->translate('Please make a decision'),
                        'disabled'    => true
                    ]);
            }

            $this->registerElement($typeElement);
            $this->registerElement($operatorElement);
            $this->registerElement($valElement);

            $removeButton = null;

            if (($conditionCount > 1) || ($conditionCount === 1 && ! $configHasZeroConditionEscalation)) {
                $removeButton = $this->createRemoveButton($position);
                if ($removeButton->hasBeenPressed()) {
                    $removePosition = $position;
                }
            }

            (new EventRuleDecorator())->decorate($valElement);
            $this->conditionListItems[$position] = new EscalationConditionListItem(
                $position,
                $typeElement,
                $operatorElement,
                $valElement,
                $removeButton
            );

            $position++;
        }

        if ($removePosition) {
            $this->getElement('condition-count')->setValue(--$conditionCount);
            if ($conditionCount === 1 && $configHasZeroConditionEscalation) {
                $idx = $removePosition === 1 ? 2 : 1;
                $this->conditionListItems[$idx]->removeRemoveButton();
                $filters->getIterator()->offsetUnset($idx);
            }
        }

        $this->condition = (new FilterRenderer($filters))->render();
        $this->add(new EscalationConditionList($this->conditionListItems));
        $this->addElement($addCondition);
    }

    /**
     * Create remove button for the condition in the given position
     *
     * @param int $count
     *
     * @return SubmitButtonElement
     */
    protected function createRemoveButton(int $count): SubmitButtonElement
    {
        $removeButton = new SubmitButtonElement(
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
}
