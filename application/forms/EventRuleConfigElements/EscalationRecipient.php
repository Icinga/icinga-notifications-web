<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\ItemList\EscalationRecipientList;
use Icinga\Module\Notifications\Widget\ItemList\EscalationRecipientListItem;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Web\Widget\Icon;

class EscalationRecipient extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-recipient'];

    /** @var EscalationRecipientListItem[] */
    protected $recipients = [];

    public function __construct($name)
    {
        parent::__construct('escalation-recipient_' . $name, []);
    }

    protected function assemble(): void
    {
        $this->addElement('hidden', 'recipient-count', ['value' => '1']);

        $addRecipientButton = $this->createElement(
            'submitButton',
            'add-recipient',
            [
                'class'          => ['add-button', 'control-button', 'spinner'],
                'label'          => new Icon('plus'),
                'title'          => $this->translate('Add Recipient'),
                'formnovalidate' => true
            ]
        );

        $this->registerElement($addRecipientButton);
        $recipientCount = (int) $this->getValue('recipient-count');
        if ($addRecipientButton->hasBeenPressed()) {
            $this->getElement('recipient-count')->setValue(++$recipientCount);
        }

        $defaultOption = ['' => sprintf(' - %s - ', $this->translate('Please choose'))];
        $removePosition = null;

        foreach (range(1, $recipientCount) as $i) {
            $this->addElement('hidden', 'id_' . $i);

            $col = $this->createElement(
                'select',
                'column_' . $i,
                [
                    'class'           => ['autosubmit', 'left-operand'],
                    'options'         => $defaultOption + $this->fetchOptions(),
                    'disabledOptions' => [''],
                    'required'        => true,
                    'value'           => $this->getPopulatedValue('column_' . $i)
                ]
            );

            $this->registerElement($col);

            $options = $defaultOption + Channel::fetchChannelNames(Database::get());

            $val = $this->createElement(
                'select',
                'val_' . $i,
                [
                    'class'           => 'right-operand',
                    'options'         => $options,
                    'disabledOptions' => [''],
                    'value'           => $this->getPopulatedValue('val_' . $i)
                ]
            );

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
                } else {
                    $val->addAttributes(['required' => true]);
                }
            }

            $this->registerElement($val);
            $removeButton = null;
            if ($recipientCount > 1) {
                $removeButton = $this->createRemoveButton($i);
                if ($removeButton->hasBeenPressed()) {
                    $removePosition = $i;
                }
            }

            $this->recipients[$i] = new EscalationRecipientListItem($i, $col, $val, $removeButton);
        }

        if ($removePosition) {
            $recipientCount -= 1;
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
     * @return SubmitButtonElement
     */
    protected function createRemoveButton(int $pos): SubmitButtonElement
    {
        $removeButton = new SubmitButtonElement(
            'remove',
            [
                'class'          => ['remove-button', 'control-button', 'spinner'],
                'label'          => new Icon('minus'),
                'title'          => $this->translate('Remove'),
                'formnovalidate' => true,
                'value'          => (string) $pos
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
        $removePosition = $this->getValue('remove');
        if ($removePosition) {
            $count += 1;
        }

        $values = [];
        for ($i = 1; $i <= $count; $i++) {
            if ($i === (int) $removePosition) {
                continue;
            }

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
}
