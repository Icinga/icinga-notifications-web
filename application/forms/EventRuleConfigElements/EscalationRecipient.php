<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Option;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-type RecipientData array{
 *     id: string,
 *     channel_id: string|null,
 *     recipient: string
 * }
 * @phpstan-type RecipientType array{
 *     id: int|null,
 *     channel_id: int|null,
 *     contact_id?: int,
 *     contactgroup_id?: int,
 *     schedule_id?: int
 * }
 */
class EscalationRecipient extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-recipient'];

    /** @var ?ConfigProviderInterface The config provider */
    #[Option(required: true)]
    protected ?ConfigProviderInterface $provider = null;

    /** @var ?SubmitButtonElement The button to remove this recipient */
    protected ?SubmitButtonElement $removeButton = null;

    /**
     * Set the button to remove this recipient
     *
     * @param SubmitButtonElement $removeButton
     *
     * @return void
     */
    public function setRemoveButton(SubmitButtonElement $removeButton): void
    {
        $this->removeButton = $removeButton;
    }

    /**
     * Prepare the recipient for display
     *
     * @param RuleEscalationRecipient $recipient
     *
     * @return array<RecipientData>
     */
    public static function prepare(RuleEscalationRecipient $recipient): array
    {
        if ($recipient->contact_id !== null) {
            $typeAndId = sprintf('contact:%u', $recipient->contact_id);
        } elseif ($recipient->contactgroup_id !== null) {
            $typeAndId = sprintf('contactgroup:%u', $recipient->contactgroup_id);
        } else {
            $typeAndId = sprintf('schedule:%u', $recipient->schedule_id);
        }

        return [
            'id' => (string) $recipient->id,
            'channel_id' => $recipient->channel_id !== null ? (string) $recipient->channel_id : null,
            'recipient' => $typeAndId
        ];
    }

    /**
     * Check whether the recipient has changed, according to the given previous recipient
     *
     * @param RuleEscalationRecipient $previousRecipient
     *
     * @return bool
     */
    public function hasChanged(RuleEscalationRecipient $previousRecipient): bool
    {
        return self::prepare($previousRecipient) != $this->getValues();
    }

    /**
     * Get the recipient to store
     *
     * @return RecipientType
     */
    public function getRecipient(): array
    {
        $typeAndId = $this->getElement('recipient')->getValue();
        [$type, $id] = explode(':', $typeAndId, 2);
        $typeIdColumn = match ($type) {
            'contact' => 'contact_id',
            'contactgroup' => 'contactgroup_id',
            'schedule' => 'schedule_id'
        };

        $recipientId = null;
        if ($this->getElement('id')->hasValue()) {
            $recipientId = (int) $this->getElement('id')->getValue();
        }

        $channelId = null;
        if ($this->getElement('channel_id')->hasValue()) {
            $channelId = (int) $this->getElement('channel_id')->getValue();
        }

        return [
            'id' => $recipientId,
            $typeIdColumn => (int) $id,
            'channel_id' => $channelId
        ];
    }

    protected function assemble(): void
    {
        $pleaseChoose = ['' => sprintf(' - %s - ', $this->translate('Please choose'))];
        $defaultChannel = ['' => $this->translate('Default Channel')];

        $this->addElement('hidden', 'id');

        $this->addElement('select', 'recipient', [
            'required' => true,
            'options' => $pleaseChoose + $this->selectRecipients(),
            'value' => '',
            'disabledOptions' => [''],
        ]);

        $this->addElement('select', 'channel_id', [
            'options' => $defaultChannel + $this->selectChannels(),
            'value' => ''
        ]);

        if ($this->removeButton !== null) {
            $this->addHtml(
                $this->removeButton->setLabel(new Icon('minus'))
                    ->setAttribute('class', ['remove-button', 'animated'])
                    ->setAttribute('title', $this->translate('Remove Recipient'))
            );
        } else {
            $this->addHtml(new HtmlElement('span', Attributes::create([
                'class' => 'remove-button-disabled',
                'title' => $this->translate('At least one recipient is required')
            ]), (new Icon('minus'))));
        }
    }

    /**
     * Create a list of recipients to use in a select element
     *
     * @return array<string, array<string, string>>
     */
    protected function selectRecipients(): array
    {
        $contacts = [];
        foreach ($this->provider?->fetchContacts() ?? [] as $contact) {
            $contacts[sprintf('contact:%u', $contact->id)] = $contact->full_name;
        }

        $contactgroups = [];
        foreach ($this->provider?->fetchContactGroups() ?? [] as $contactgroup) {
            $contactgroups[sprintf('contactgroup:%u', $contactgroup->id)] = $contactgroup->name;
        }

        $schedules = [];
        foreach ($this->provider?->fetchSchedules() ?? [] as $schedule) {
            $schedules[sprintf('schedule:%u', $schedule->id)] = $schedule->name;
        }

        $recipients = [];
        if (! empty($contacts)) {
            $recipients[$this->translate('Contacts')] = $contacts;
        }

        if (! empty($contactgroups)) {
            $recipients[$this->translate('Contact Groups')] = $contactgroups;
        }

        if (! empty($schedules)) {
            $recipients[$this->translate('Schedules')] = $schedules;
        }

        return $recipients;
    }

    /**
     * Create a list of channels to use in a select element
     *
     * @return array<int, string>
     */
    protected function selectChannels(): array
    {
        $channels = [];
        foreach ($this->provider?->fetchChannels() ?? [] as $channel) {
            $channels[$channel->id] = $channel->name;
        }

        return $channels;
    }
}
