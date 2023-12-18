<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class EscalationRecipientList extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalation-recipient-list'];

    protected $tag = 'ul';

    /** @var EscalationRecipientListItem[] Recipient list items of the escalation */
    protected $recipients;

    /**
     * Create a list of escalation recipients
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

            if ($removedPosition !== null) {
                $recipient->setPosition($position - 1);
            }

            if ($position !== $removedPosition) {
                $this->addHtml($recipient);
            }
        }
    }
}
