<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\ItemList\EscalationRecipientList;
use Icinga\Module\Notifications\Widget\ItemList\EscalationRecipientListItem;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SelectElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Web\Widget\Icon;

class EscalationRecipient extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-recipient'];

    /** @var EscalationRecipientListItem[]  */
    protected $recipients = [];

    protected function assemble(): void
    {
        $this->addElement(
            'hidden',
            'recipient-count',
            ['value' => '1']
        );

        /** @var SubmitButtonElement $addRecipientButton */
        $addRecipientButton = $this->createElement(
            'submitButton',
            'add-recipient',
            [
                'class' => ['add-button', 'control-button', 'spinner'],
                'label' => new Icon('plus'),
                'title' => $this->translate('Add Recipient'),
                'formnovalidate' => true
            ]
        );

        $this->registerElement($addRecipientButton);
        /** @var int $recipientCount */
        $recipientCount = $this->getValue('recipient-count');
        if ($addRecipientButton->hasBeenPressed()) {
            $recipientCount += 1;
            $this->getElement('recipient-count')->setValue($recipientCount);
        }

        $removePosition = null;
        foreach (range(1, $recipientCount) as $i) {
            $this->addElement(
                'hidden',
                'id_' . $i
            );

            /** @var BaseFormElement $col */
            $col = $this->createElement(
                'select',
                'column_' . $i,
                [
                    'class'             => ['autosubmit', 'left-operand'],
                    'options'           => [
                            '' => sprintf(' - %s - ', $this->translate('Please choose'))
                        ] + $this->fetchOptions(),
                    'disabledOptions'   => [''],
                    'required'          => true,
                    'value'             => $this->getPopulatedValue('column_' . $i)
                ]
            );

            $this->registerElement($col);

            $options = ['' => sprintf(' - %s - ', $this->translate('Please choose'))];
            $options += Channel::fetchChannelNames(Database::get());

            /** @var SelectElement $val */
            $val = $this->createElement(
                'select',
                'val_' . $i,
                [
                    'class'             => ['autosubmit', 'right-operand'],
                    'options'           => $options,
                    'disabledOptions'   => [''],
                    'value'             => $this->getPopulatedValue('val_' . $i)
                ]
            );

            /** @var string $recipientVal */
            $recipientVal = $this->getValue('column_' . $i);
            if ($recipientVal !== null) {
                $recipient = explode('_', $recipientVal);
                if ($recipient[0] === 'contact') {
                    $options[''] = $this->translate('Default User Channel');

                    $val->setOptions($options);

                    $val->setDisabledOptions([]);

                    if ($this->getPopulatedValue('val_' . $i, '') === '') {
                        $val->addAttributes(['class' => 'default-channel']);
                    }
                }
            } else {
                /** @var BaseFormElement $val */
                $val = $this->createElement('text', 'val_' . $i, [
                    'class'       => 'right-operand',
                    'placeholder' => $this->translate('Please make a decision'),
                    'disabled'    => true,
                    'value'       => $this->getPopulatedValue('val_' . $i)
                ]);
            }

            $this->registerElement($val);

            /** @var ?SubmitButtonElement $removeButton */
            $removeButton = $this->createRemoveButton($i);

            $this->recipients[$i] = new EscalationRecipientListItem(
                $col,
                $val,
                $removeButton
            );
        }

        /** @var string $removePosition */
        $removePosition = $this->getValue('remove');
        if ($removePosition) {
            unset($this->recipients[$removePosition]);
            $recipientCount -= 1;
            if ($recipientCount === 1 && $removePosition === '2') {
                $this->recipients[1]->removeButton = null;
            } else {
                for ($n = (int) $removePosition; $n <= $recipientCount; $n++) {
                    $nextCount = $n + 1;
                    $this->recipients[$nextCount]->recipient->setName('column_' . $n);
                    $this->recipients[$nextCount]->channel->setName('val_' . $n);
                    if ($recipientCount === 1) {
                        $this->recipients[$nextCount]->removeButton = null;
                    } elseif ($this->recipients[$nextCount]->removeButton) {
                        $this->recipients[$nextCount]->removeButton->setValue((string) $n);
                    }
                }
            }

            $this->getElement('recipient-count')->setValue($recipientCount);
        }

        $this->add(new EscalationRecipientList($this->recipients));

        $this->addElement($addRecipientButton);
    }

    /**
     * Fetch recipient options
     *
     * @return array<string, array<string, string>>
     */
    protected function fetchOptions(): array
    {
        $options = [];
        /** @var Contact $contact */
        foreach (Contact::on(Database::get()) as $contact) {
            $options['Contacts']['contact_' . $contact->id] = $contact->full_name;
        }

        /** @var Contactgroup $contactgroup */
        foreach (Contactgroup::on(Database::get()) as $contactgroup) {
            $options['Contact Groups']['contactgroup_' . $contactgroup->id] = $contactgroup->name;
        }

        /** @var Schedule $schedule */
        foreach (Schedule::on(Database::get()) as $schedule) {
            $options['Schedules']['schedule_' . $schedule->id] = $schedule->name;
        }

        return $options;
    }

    /**
     * Create remove button for the recipient in the given position
     *
     * @param int $pos
     *
     * @return FormElement|null
     */
    protected function createRemoveButton(int $pos): ?FormElement
    {
        /** @var string|int $recipientCount */
        $recipientCount = $this->getValue('recipient-count');
        if ((int) $recipientCount === 1) {
            return null;
        }

        $removeButton = $this->createElement(
            'submitButton',
            'remove',
            [
                'class'             => ['remove-button', 'control-button', 'spinner'],
                'label'             => new Icon('minus'),
                'title'             => $this->translate('Remove'),
                'formnovalidate'    => true,
                'value'             => (string) $pos
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
     * Get recipients of the escalation
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecipients(): array
    {
        /** @var int $count */
        $count = $this->getValue('recipient-count');

        /** @var array<int, array<string, mixed>> $values */
        $values = [];
        for ($i = 1; $i <= $count; $i++) {
            $value = [];
            $value['channel_id'] = $this->getValue('val_' . $i);
            $value['id'] = $this->getValue('id_' . $i);

            /** @var ?string $columnName */
            $columnName = $this->getValue('column_' . $i);

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

    public function renderUnwrapped()
    {
        $this->ensureAssembled();

        if ($this->isEmpty()) {
            return '';
        }

        return parent::renderUnwrapped();
    }
}
