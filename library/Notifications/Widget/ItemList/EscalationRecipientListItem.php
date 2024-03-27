<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\SubmitButtonElement;

class EscalationRecipientListItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'option'];

    protected $tag = 'li';

    /** @var ?SubmitButtonElement Remove button for the recipient */
    public $removeButton;

    /** @var BaseFormElement Recipient name field */
    public $recipient;

    /** @var BaseFormElement Recipient channel field */
    public $channel;

    public function __construct(
        BaseFormElement $reipient,
        BaseFormElement $channel,
        ?SubmitButtonElement $removeButton
    ) {
        $this->recipient = $reipient;
        $this->channel = $channel;
        $this->removeButton = $removeButton;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->recipient,
            $this->channel,
            $this->removeButton
        ]);
    }
}
