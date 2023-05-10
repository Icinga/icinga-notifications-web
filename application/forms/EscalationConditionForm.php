<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use Icinga\Module\Noma\Web\Form\EventRuleDecorator;
use ipl\Html\Contract\FormElement;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

class EscalationConditionForm extends BaseEscalationForm
{
    /** @var bool Whether to delete the remove button */
    protected $deleteRemoveButton;

    public function __construct(?int $count)
    {
        $this->addAttributes(['class' => 'escalation-condition-form']);

        parent::__construct($count ?? 0);
    }

    protected function assembleElements(): void
    {
        $end = $this->count;
        if ($this->isAddPressed) {
            $end++;
        }

        foreach (range(1, $end) as $count) {
            $col = $this->createElement(
                'select',
                'column' . $count,
                [
                    'class'             => ['autosubmit', 'left-operand'],
                    'options'           => [
                        '' => sprintf(' - %s - ', $this->translate('Please choose')),
                        'incident_severity' => $this->translate('Incident Severity'),
                        'incident_age' => $this->translate('Incident Age')
                    ],
                    'disabledOptions'   => [''],
                    'required'          => true
                ]
            );

            $operators = ['=', '>', '<'];
            $op = $this->createElement(
                'select',
                'operator'. $count,
                [
                    'class' => ['class' => 'operator-input', 'autosubmit'],
                    'options' => array_combine($operators, $operators),
                    'required' => true
                ]
            );

            switch ($this->getPopulatedValue('column' . $count)) {
                case 'incident_severity':
                    $val = $this->createElement(
                        'select',
                        'value' . $count,
                        [
                            'required' => true,
                            'class' => ['autosubmit', 'right-operand'],
                            'options' => [
                                'ok' => $this->translate('Ok', 'noma.severity'),
                                'debug' => $this->translate('Debug', 'noma.severity'),
                                'info' => $this->translate('Information', 'noma.severity'),
                                'notice' => $this->translate('Notice', 'noma.severity'),
                                'warning' => $this->translate('Warning', 'noma.severity'),
                                'err' => $this->translate('Error', 'noma.severity'),
                                'crit' => $this->translate('Critical', 'noma.severity'),
                                'alert' => $this->translate('Alert', 'noma.severity'),
                                'emerg' => $this->translate('Emergency', 'noma.severity')
                            ]
                        ]
                    );

                    if (
                        $this->getPopulatedValue('type' . $count) !== 'incident_severity'
                        && $this->getPopulatedValue('type' . $count) !== null
                    ) {
                        $this->clearPopulatedValue('type' . $count);
                        $this->clearPopulatedValue('value' . $count);
                    }

                    $this->addElement('hidden', 'type' . $count, [
                        'ignore' => true,
                        'value' => 'incident_severity'
                    ]);

                    break;
                case 'incident_age':
                    $val = $this->createElement(
                        'text',
                        'value'. $count,
                        [
                            'required' => true,
                            'class' => ['autosubmit', 'right-operand'],
                            'validators' => [new CallbackValidator(function ($value, $validator) {
                                if (! preg_match('~^\d+(?:\.?\d*)?[hms]{1}$~', $value)) {
                                    $validator->addMessage($this->translate(
                                        'Only numbers with optional fractions (separated by a dot)'
                                        . ' and one of these suffixes are allowed: h, m, s'
                                    ));

                                    return false;
                                }

                                return true;
                            })]
                        ]
                    );

                    if (
                        $this->getPopulatedValue('type' . $count) !== 'incident_age'
                        && $this->getPopulatedValue('type' . $count) !== null
                    ) {
                        $this->clearPopulatedValue('type' . $count);
                        $this->clearPopulatedValue('value' . $count);
                    }

                    $this->addElement('hidden', 'type' . $count, [
                        'ignore' => true,
                        'value' => 'incident_age'
                    ]);

                    break;
                default:
                    $val = $this->createElement('text', 'value' . $count, [
                        'class'       => 'right-operand',
                        'placeholder' => $this->translate('Please make a decision'),
                        'disabled' => true
                    ]);
            }

            $this->registerElement($col);
            $this->registerElement($op);
            $this->registerElement($val);

            (new EventRuleDecorator())->decorate($val);

            $this->options[$count] = Html::tag(
                'li',
                ['class' => 'option'],
                [$col, $op, $val, $this->createRemoveButton($count)]
            );
        }

        $this->handleRemove();

        $this->add(Html::tag('ul', ['class' => 'options'], $this->options));
    }

    public function getValues()
    {
        $filter = Filter::any();

        if ($this->count > 0) { // if count is 0, loop runs in reverse direction
            foreach (range(1, $this->count) as $count) {
                if ($this->removedOptionNumber === $count) {
                    continue; // removed option
                }

                $filterStr = $this->getValue('column' . $count, 'placeholder')
                    . $this->getValue('operator' . $count)
                    . $this->getValue('value' . $count);

                $filter->add(QueryString::parse($filterStr));
            }
        }

        if ($this->isAddPressed) {
            $filter->add(QueryString::parse('placeholder='));
        }

        return QueryString::render($filter);
    }

    public function populate($values)
    {
        foreach ($values as $key => $condition) {
            if (! is_int($key)) {
                // csrf token and uid
                continue;
            }

            $count = $key + 1;
            if (empty($condition)) { // when other conditions are removed and only 1 pending with no values
                $values['column' . $count] = null;
                $values['operator' . $count] = null;
                $values['value' . $count] = null;

                continue;
            }

            $filter = QueryString::parse($condition);

            $values['column' . $count] = $filter->getColumn() === 'placeholder' ? null : $filter->getColumn();
            $values['operator' . $count] = QueryString::getRuleSymbol($filter);
            $values['value' . $count] = $filter->getValue();
        }

        return parent::populate($values);
    }

    protected function createRemoveButton(int $count): ?FormElement
    {
        if ($this->deleteRemoveButton && $this->count === 1 && ! $this->isAddPressed) {
            return null;
        }

        $removeButton = $this->createElement(
            'submitButton',
            'remove_' . $count,
            [
                'class'             => ['remove-button', 'control-button', 'spinner'],
                'label'             => new Icon('minus'),
                'title'             => $this->translate('Remove'),
                'formnovalidate'    => true
            ]
        );

        $this->registerElement($removeButton);

        return $removeButton;
    }

    protected function handleRemove(): void
    {
        $button = $this->getPressedSubmitElement();

        if ($button && $button->getName() !== 'add') {
            [$name, $toRemove] = explode('_', $button->getName(), 2);

            $this->removedOptionNumber = (int) $toRemove;
            $optionCount = count($this->options);

            for ($i = $toRemove; $i < $optionCount; $i++) {
                $nextCount = $i + 1;
                $this->getElement('column' . $nextCount)->setName('column' . $i);
                $this->getElement('operator' . $nextCount)->setName('operator' . $i);
                $this->getElement('value' . $nextCount)->setName('value' . $i);

                $this->getElement('remove_' . $nextCount)->setName('remove_' . $i);
            }

            unset($this->options[$toRemove]);

            if ($this->deleteRemoveButton && count($this->options) === 1) {
                $key = array_key_last($this->options);
                $this->options[$key]->remove($this->getElement('remove_' . $key));
            }
        }

        if (empty($this->options)) {
            $this->addAttributes(['class' => 'count-zero-escalation-condition-form']);
        } else {
            $this->getAttributes()
                ->remove('class', 'count-zero-escalation-condition-form');
        }
    }

    /**
     * Whether to delete the remove button
     *
     * @param bool $delete
     *
     * @return $this
     */
    public function deleteRemoveButton(bool $delete = true): self
    {
        $this->deleteRemoveButton = $delete;

        return $this;
    }
}
