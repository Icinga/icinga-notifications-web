<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormElement;
use ipl\Html\Html;

class Escalations extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalations'];

    protected $tag = 'ul';

    /** @var Escalation[] */
    private $escalations;

    /** @var FormElement  */
    private $addButton;

    /**
     * Create Escalations for an event rule
     *
     * @param Escalation[] $escalations
     * @param FormElement $addButton
     */
    public function __construct(array $escalations, FormElement $addButton)
    {
        $this->escalations = $escalations;
        $this->addButton = $addButton;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->escalations,
            Html::tag('li', ['class' => 'add-escalation'], $this->addButton)
        ]);
    }
}
