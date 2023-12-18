<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class EscalationRecipientList extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'options'];

    protected $tag = 'ul';

    /** @var EscalationRecipientListItem[] Escalation recipients */
    private $recipients;

    /**
     * Create EscalationRecipientList for an escalation
     *
     * @param EscalationRecipientListItem[] $recipients
     */
    public function __construct(array $recipients)
    {
        $this->recipients = $recipients;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->recipients
        ]);
    }
}
