<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\FormElement\SubmitButtonElement;

class Escalations extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalations'];

    protected $tag = 'ul';

    /** @var Escalation[] Escalation list items */
    protected $escalations;

    /** @var SubmitButtonElement Escalation add button */
    protected $addButton;

    /**
     * Create the escalations list
     *
     * @param Escalation[] $escalations
     * @param SubmitButtonElement  $addButton
     */
    public function __construct(array $escalations, SubmitButtonElement $addButton)
    {
        $this->escalations = $escalations;
        $this->addButton = $addButton;
    }

    protected function assemble(): void
    {
        $this->addHtml(...$this->escalations);
        $this->addHtml($this->addButton);
    }
}
