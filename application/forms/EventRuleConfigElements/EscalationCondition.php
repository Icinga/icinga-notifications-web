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
    protected $conditions = [];

    /** @var EventRuleConfigForm */
    protected $configForm;

    /** @var string */
    protected $prefix;

    public function __construct(string $prefix, EventRuleConfigForm $configForm)
    {
        $this->prefix = $prefix;
        $this->configForm = $configForm;

        parent::__construct('escalation-condition_' . $this->prefix);
    }

    protected function assemble(): void
    {
        $this->addElement('hidden', 'condition-count');
        // Escalation Id to which the condition belongs
        $this->addElement('hidden', 'id');

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

        $conditionCount = $this->getValue('condition-count');
        $zeroConditions = $this->configForm->getValue('zero-condition-escalation') === $this->prefix;
        $defaultCount = 1;
        $configHasZeroConditionEscalation = $this->configForm->hasZeroConditionEscalation();
        if ($zeroConditions && $configHasZeroConditionEscalation) {
            $defaultCount = 0;
            $conditionCount = $defaultCount;
        } else {
            $conditionCount = $conditionCount === null ? $defaultCount : (int) $conditionCount;
        }

        if ($addCondition->hasBeenPressed()) {
            ++$conditionCount;
            if ($defaultCount === 0 && $conditionCount === 1) {
                $configHasZeroConditionEscalation = false;
            }
        }

        $this->getElement('condition-count')->setValue($conditionCount);
        if ($conditionCount === 0) {
            $this->addAttributes(['class' => 'zero-escalation-condition']);
            $this->addElement($addCondition);

            return;
        }

        $this->getAttributes()->remove('class', 'zero-escalation-condition');
        $removePosition = null;

        for ($i = 1; $i <= $conditionCount; $i++) {
            $col = $this->createElement(
                'select',
                'column_' . $i,
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
            $op = $this->createElement(
                'select',
                'operator_' . $i,
                [
                    'class'    => 'operator-input',
                    'options'  => array_combine($operators, $operators),
                    'required' => true
                ]
            );

            $valName = 'val_' . $i;
            switch ($this->getPopulatedValue('column_' . $i)) {
                case 'incident_severity':
                    $val = $this->createElement(
                        'select',
                        $valName,
                        [
                            'class'   => 'right-operand',
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

                    break;
                case 'incident_age':
                    $val = $this->createElement(
                        'text',
                        $valName,
                        [
                            'required'   => true,
                            'class'      => 'right-operand',
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
                    $val = $this->createElement('text', $valName, [
                        'class'       => 'right-operand',
                        'placeholder' => $this->translate('Please make a decision'),
                        'disabled'    => true
                    ]);
            }

            $this->registerElement($col);
            $this->registerElement($op);
            $this->registerElement($val);

            $removeButton = null;

            if (($conditionCount > 1) || ($conditionCount === 1 && ! $configHasZeroConditionEscalation)) {
                $removeButton = $this->createRemoveButton($i);
                if ($removeButton->hasBeenPressed()) {
                    $removePosition = $i;
                }
            }

            (new EventRuleDecorator())->decorate($val);
            $this->conditions[$i] = new EscalationConditionListItem($i, $col, $op, $val, $removeButton);
        }

        if ($removePosition) {
            $this->getElement('condition-count')->setValue(--$conditionCount);
            if ($conditionCount === 1 && $configHasZeroConditionEscalation) {
                $idx = $removePosition === 1 ? 2 : 1;
                $this->conditions[$idx]->setRemoveButton(null);
            }
        }

        $this->add(new EscalationConditionList($this->conditions));
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

    /**
     * Get the rendered condition
     *
     * @return string
     */
    public function getCondition(): string
    {
        $count = (int) $this->getValue('condition-count');
        if ($count === 0) {
            return '';
        }

        $filter = Filter::any();
        $removePosition = (int) $this->getValue('remove');
        if ($removePosition) {
            $count += 1;
        }

        foreach (range(1, $count) as $count) {
            if ($count === $removePosition) {
                continue;
            }

            $chosenType = $this->getValue('column_' . $count, 'placeholder');

            $filterStr = $chosenType
                . $this->getValue('operator_' . $count)
                . ($this->getValue('val_' . $count) ?? ($chosenType === 'incident_severity' ? 'ok' : ''));

            $filter->add(QueryString::parse($filterStr));
        }

        return (new FilterRenderer($filter))
            ->render();
    }
}
