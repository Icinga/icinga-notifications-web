<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class EscalationRecipientList extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'options'];

    protected $tag = 'ul';

    /** @var EscalationRecipientListItem[] Recipient list items of the escalation */
    protected $recipients;

    /**
     * Create recipients list of the escalation
     *
     * @param EscalationRecipientListItem[] $recipients
     */
    public function __construct(array $recipients)
    {
        $this->recipients = $recipients;
    }

    protected function assemble(): void
    {
        $removedPosition = null;
        $recipientCount = count($this->recipients);
        foreach ($this->recipients as $position => $recipient) {
            if ($recipient->hasBeenRemoved()) {
                $removedPosition = $position;
                --$recipientCount;

                continue;
            }

            if ($removedPosition) {
                $recipient->setPosition($position - 1);
            }
        }

        foreach ($this->recipients as $position => $recipient) {
            if ($position !== $removedPosition) {
                if ($recipientCount === 1) {
                    $recipient->removeRemoveButton();
                }

                $this->addHtml($recipient);
            }
        }
    }
}
