<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\RuleEntryForm;

use Icinga\Module\Notifications\Form\Data\RuleEntryRecipient as RuleEntryRecipientData;
use Icinga\Module\Notifications\Model\RuleEntryRecipient as RuleEntryRecipientModel;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type RecipientValues from RuleEntryRecipient
 */
class RuleEntryRecipients extends FieldsetElement
{
    use ConfigProvider;
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalation-recipients'];

    protected function createAddButton(): SubmitButtonElement
    {
        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Recipient'),
            'label' => [
                new Icon('plus'),
                new HtmlElement('span', content: Text::create($this->translate('Add Recipient')))
            ],
            'class' => ['add-button', 'animated', 'link-button']
        ]);

        $button->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'add-button-wrapper'])));

        return $button;
    }

    protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement
    {
        $recipient = new RuleEntryRecipient($no, ['provider' => $this->provider]);
        if ($removeButton !== null) {
            $recipient->setRemoveButton($removeButton);
        }

        return $recipient;
    }

    /**
     * Prepare the recipients for display
     *
     * @param iterable<RuleEntryRecipientModel> $recipients
     *
     * @return array<RecipientValues>
     */
    public static function prepare(iterable $recipients): array
    {
        $values = [];
        foreach ($recipients as $recipient) {
            $values[] = RuleEntryRecipient::prepare($recipient);
        }

        return $values;
    }

    /**
     * Get the recipients to store
     *
     * @return RuleEntryRecipientData[]
     */
    public function getRecipients(): array
    {
        $recipients = [];
        foreach ($this->ensureAssembled()->getElements() as $element) {
            if ($element instanceof RuleEntryRecipient) {
                $recipients[] = $element->getRecipient();
            }
        }

        return $recipients;
    }
}
