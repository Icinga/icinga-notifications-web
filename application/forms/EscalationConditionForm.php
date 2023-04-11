<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Filter\QueryString;

class EscalationConditionForm extends BaseEscalationForm
{
    public function __construct(?int $count)
    {
        $this->addAttributes(['class' => 'escalation-condition-form']);

        parent::__construct($count ?? 0);
    }

    protected function assembleElements(): void
    {
        $start = 1;
        $end = $this->count;
        if ($this->isAddPressed) {
            $start = $this->count + 1;
            $end = $start;
        }

        foreach (range($start, $end) as $count) {
            $col = $this->createElement(
                'select',
                'column' . $count,
                [
                    'class'             => 'autosubmit',
                    'options'           => [
                        ''          => sprintf(' - %s - ', $this->translate('Please choose')),
                        'age'       => $this->translate('Escalation Age'),
                        'incident'  => $this->translate('Incident'),
                        'ack'       => $this->translate('Acknowledge')
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
                    'class'             => 'autosubmit',
                    'options'           => array_combine($operators, $operators),
                    'required'          => true
                ]
            );

            $val = $this->createElement(
                'text',
                'value'. $count,
                [
                    'class'     => 'autosubmit',
                    'required'  => true
                ]
            );

            $this->registerElement($col);
            $this->registerElement($op);
            $this->registerElement($val);

            $this->lastContent = Html::tag('div', ['class' => 'condition'], [$col, $op, $val]);

            $this->add($this->lastContent);
        }
    }

    public function getValues()
    {
        $filter = Filter::any();

        if ($this->count > 0) { // if count is 0, loop runs in reverse direction
            foreach (range(1, $this->count) as $count) {
                $filterStr = $this->getValue('column' . $count, 'placeholder')
                    . $this->getValue('operator' . $count)
                    . $this->getValue('value' . $count);

                $filter->add(QueryString::parse($filterStr));
            }
        }

        if ($this->isAddPressed) {
            $filter->add(QueryString::parse('placeholder'));
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
}
