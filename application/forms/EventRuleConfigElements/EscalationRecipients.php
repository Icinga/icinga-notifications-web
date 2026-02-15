<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Option;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type RecipientData from EscalationRecipient
 */
class EscalationRecipients extends FieldsetElement
{
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalation-recipients'];

    /** @var ?ConfigProviderInterface The config provider */
    #[Option(required: true)]
    protected ?ConfigProviderInterface $provider = null;

    protected function createAddButton(): SubmitButtonElement
    {
        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Recipient'),
            'label' => new Icon('plus'),
            'class' => ['add-button', 'animated']
        ]);

        $button->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'add-button-wrapper'])));

        return $button;
    }

    protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement
    {
        $recipient = new EscalationRecipient($no, ['provider' => $this->provider]);
        if ($removeButton !== null) {
            $recipient->setRemoveButton($removeButton);
        }

        return $recipient;
    }

    /**
     * Prepare the recipients for display
     *
     * @param iterable<RuleEscalationRecipient> $recipients
     *
     * @return array<RecipientData>
     */
    public static function prepare(iterable $recipients): array
    {
        $values = [];
        foreach ($recipients as $recipient) {
            $values[] = EscalationRecipient::prepare($recipient);
        }

        return $values;
    }

    /**
     * Get the recipients to store
     *
     * @return array<EscalationRecipient>
     */
    public function getRecipients(): array
    {
        $recipients = [];
        foreach ($this->ensureAssembled()->getElements() as $element) {
            if ($element instanceof EscalationRecipient) {
                $recipients[] = $element;
            }
        }

        return $recipients;
    }
}
