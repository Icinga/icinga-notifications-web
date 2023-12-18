<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class Escalations extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalations'];

    protected $tag = 'ul';

    /** @var Escalation[] Escalation list items */
    protected $escalations;

    /**
     * Create the escalations list
     *
     * @param Escalation[] $escalations
     */
    public function __construct(array $escalations)
    {
        $this->escalations = $escalations;
    }

    protected function assemble(): void
    {
        $this->addHtml(...$this->escalations);
    }
}
