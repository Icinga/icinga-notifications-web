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
    protected $recipientListItems = [];

    /** @var array */
    protected $recipients = [];

    public function __construct($name)
    {
        parent::__construct('escalation-recipient_' . $name);
    }

    protected function assemble(): void
    {
        $recipientCount = count($this->recipients);
        $this->addElement('hidden', 'recipient-count', ['value' => $recipientCount]);

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

        if ($addRecipientButton->hasBeenPressed()) {
            $this->getElement('recipient-count')->setValue(++$recipientCount);
            $this->recipients[$recipientCount] = [];
        }

        $defaultOption = ['' => sprintf(' - %s - ', $this->translate('Please choose'))];
        $recipientOptions = $defaultOption + $this->fetchOptions();
        $channelOptions = $defaultOption + Channel::fetchChannelNames(Database::get());
        $removePosition = null;

        $position = 1;
        foreach ($this->recipients as $escalationRecipient) {
            $this->addElement(
                'hidden',
                'id_' . $position,
                ['value' => $escalationRecipient['id'] ?? null]
            );

            $recipient = array_filter($escalationRecipient, function ($k) {
                return in_array($k, ['contact_id', 'contactgroup_id', 'schedule_id']);
            }, ARRAY_FILTER_USE_KEY);

            if (empty($recipient)) {
                $recipientVal = $this->getPopulatedValue('column_' . $position, '');
            } else {
                // Trim the trailing '_id' from the array key
                $recipientType = substr(array_key_first($recipient) ?? '', 0, -3);
                $recipientVal = $recipientType . '_' . array_shift($recipient);
            }

            $col = $this->createElement(
                'select',
                'column_' . $position,
                [
                    'class'           => ['autosubmit', 'left-operand'],
                    'options'         => $recipientOptions,
                    'disabledOptions' => [''],
                    'required'        => true,
                    'value'           => $recipientVal
                ]
            );

            $this->registerElement($col);

            if (isset($escalationRecipient['channel_id'])) {
                $channelId = (int) $escalationRecipient['channel_id'];
            } else {
                $channelId = '';
            }

            $val = $this->createElement(
                'select',
                'val_' . $position,
                [
                    'class'           => ['autosubmit', 'right-operand'],
                    'options'         => $channelOptions,
                    'disabledOptions' => [''],
                    'value'           => $this->getPopulatedValue('val_' . $position) ?? $channelId
                ]
            );

            $recipientVal = $this->getValue('column_' . $position);
            if ($recipientVal !== null) {
                $recipientType = explode('_', $recipientVal)[0];
                if ($recipientType === 'contact') {
                    $val->setOptions(['' => $this->translate('Default Channel')] + $channelOptions);
                    $val->setDisabledOptions([]);

                    if ($this->getPopulatedValue('val_' . $position, '') === '') {
                        $val->addAttributes(['class' => 'default-channel']);
                    }
                } else {
                    $val->addAttributes(['required' => true]);
                }
            } else {
                $val = $this->createElement('text', 'val_' . $position, [
                    'class'       => 'right-operand',
                    'placeholder' => $this->translate('Please make a decision'),
                    'disabled'    => true,
                    'value'       => $this->getPopulatedValue('val_' . $position)
                ]);
            }

            $this->registerElement($val);
            $removeButton = null;
            if ($recipientCount > 1) {
                $removeButton = $this->createRemoveButton($position);
                if ($removeButton->hasBeenPressed()) {
                    $removePosition = $position;
                }
            }

            $this->recipientListItems[$position] = new EscalationRecipientListItem(
                $position++,
                $col,
                $val,
                $removeButton
            );
        }

        if ($removePosition) {
            $recipientCount -= 1;
            $this->getElement('recipient-count')->setValue($recipientCount);
            if ($recipientCount === 1) {
                $idx = $removePosition === 1 ? 2 : 1;
                $this->recipientListItems[$idx]->removeRemoveButton();
            }
        }

        $this->add(new EscalationRecipientList($this->recipientListItems));

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
            $options[$this->translate('Contacts')]['contact_' . $contact->id] = $contact->full_name;
        }

        /** @var Contactgroup $contactgroup */
        foreach (Contactgroup::on(Database::get()) as $contactgroup) {
            $options[$this->translate('Contact Groups')]['contactgroup_' . $contactgroup->id] = $contactgroup->name;
        }

        /** @var Schedule $schedule */
        foreach (Schedule::on(Database::get()) as $schedule) {
            $options[$this->translate('Schedules')]['schedule_' . $schedule->id] = $schedule->name;
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

    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;

        if (empty($this->recipients)) {
            $this->recipients = [0 => []];
        }

        return $this;
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
            // This is needed as the count is already reduced when the remove button of a recipient is clicked, but the
            // registered element is not yet removed from the form. Hence, needs to be skipped in the loop when fetching
            // the recipients
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

            /** @var ?string $recipient */
            $recipient = $this->getValue('column_' . $i);

            if ($recipient === null) {
                $values[] = $value;

                continue;
            }

            [$recipientType, $id] = explode('_', $recipient, 2);

            $value[$recipientType . '_id'] = $id;

            $values[] = $value;
        }

        return $values;
    }
}
