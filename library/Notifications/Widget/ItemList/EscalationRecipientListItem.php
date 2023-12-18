<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\SubmitButtonElement;

class EscalationRecipientListItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'option'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button for the recipient */
    protected $removeButton;

    /** @var FormElement Recipient name */
    protected $recipient;

    /** @var FormElement Recipient channel */
    protected $channel;

    /** @var int */
    protected $position;

    /**
     * Create the recipient list item of the escalation
     *
     * @param int $position
     * @param FormElement $recipient
     * @param FormElement $channel
     * @param ?SubmitButtonElement $removeButton
     */
    public function __construct(
        int $position,
        FormElement $recipient,
        FormElement $channel,
        ?SubmitButtonElement $removeButton
    ) {
        $this->position = $position;
        $this->recipient = $recipient;
        $this->channel = $channel;
        $this->removeButton = $removeButton;
    }

    /**
     * Return whether the condition has been removed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        return $this->removeButton && $this->removeButton->hasBeenPressed();
    }

    /**
     * Set the position of the condition list item
     *
     * @param int $position
     *
     * @return $this
     */
    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function removeRemoveButton(): self
    {
        $this->removeButton = null;

        return $this;
    }

    protected function assemble(): void
    {
        $this->recipient->setAttribute('name', 'column_' . $this->position);
        $this->channel->setAttribute('name', 'val_' . $this->position);

        $this->addHtml($this->recipient, $this->channel);
        if ($this->removeButton) {
            $this->removeButton->setSubmitValue((string) $this->position);

            $this->addHtml($this->removeButton);
        }
    }
}
