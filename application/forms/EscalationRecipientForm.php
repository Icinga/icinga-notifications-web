<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Contact;
use ipl\Html\Html;

class EscalationRecipientForm extends BaseEscalationForm
{
    public function __construct(?int $count)
    {
        $this->addAttributes(['class' => 'escalation-recipient-form']);

        parent::__construct($count ?? 1);
    }

    protected function fetchOptions(): array
    {
        $options = [];
        foreach (Contact::on(Database::get()) as $contact) {
            $options['Contacts']['contact_' . $contact->id] = $contact->full_name;
        }

        /*foreach (Contactgroup::on(Database::get()) as $contactgroup) {
            $options['Contact Groups']['contactgroup_' . $contactgroup->id] = $contactgroup->name;
        }

        foreach (Schedule::on(Database::get()) as $schedule) {
            $options['Schedules']['schedule_' . $schedule->id] = $schedule->name;
        }*/

        return $options;
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
                    'options'           => ['' => sprintf(' - %s - ', $this->translate('Please choose'))] + $this->fetchOptions(),
                    'disabledOptions'   => [''],
                    'required'          => true
                ]
            );

            $op = $this->createElement(
                'text',
                'operator'. $count,
                [
                    'class'     => 'operator-input',
                    'value'     => '=',
                    'disabled'  => true
                ]
            );

            $val = $this->createElement(
                'select',
                'value'. $count,
                [
                    'class'     => ['autosubmit', 'right-operand'],
                    'options'   => [
                        ''              => sprintf(' - %s - ', $this->translate('Please choose')),
                        'email'         => 'E-Mail',
                        'rocket.chat'   => 'Rocket.Chat'
                    ],
                    'disabledOptions'   => [''],
                    'required'          => true
                ]
            );

            $this->registerElement($col);
            $this->registerElement($op);
            $this->registerElement($val);

            $this->options[$count] = Html::tag('li', [$col, $op, $val, $this->createRemoveButton($count)]);
        }

        $this->handleRemove();

        $this->add(Html::tag('ul', ['class' => 'options'], $this->options));
    }

    public function getValues()
    {
        $end = $this->count;
        if ($this->isAddPressed) {
            $end++;
        }

        $values = [];
        foreach (range(1, $end) as $count) {
            if ($this->removedOptionNumber === $count) {
                continue; // removed option
            }

            $value = [];
            $value['channel_type'] = $this->getValue('value' . $count);

            $columnName = $this->getValue('column' . $count);

            if ($columnName === null) {
                $values[] = $value;
                continue;
            }

            [$columnName, $id] = explode('_', $columnName, 2);

            $value[$columnName . '_id'] = $id;

            $values[] = $value;
        }

        return $values;
    }

    public function populate($values)
    {
        foreach ($values as $key => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $elementName => $elementValue) {
                    if ($elementValue === null) {
                        continue;
                    }

                    $count = $key + 1;
                    $selectedOption = str_replace('id', $elementValue, $elementName, $replaced);
                    if ($replaced) {
                        $values['column' . $count] = $selectedOption;
                    } elseif ($elementName === 'channel_type') {
                        $values['value' . $count] = $elementValue;
                    }
                }
            }
        }

        return parent::populate($values);
    }
}
