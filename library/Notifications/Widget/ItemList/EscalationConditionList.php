<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class EscalationConditionList extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'options'];

    protected $tag = 'ul';

    /** @var EscalationConditionListItem[] Escalation conditions */
    private $conditions;

    /**
     * Create EscalationConditionListItem for an escalation
     *
     * @param EscalationConditionListItem[] $conditions
     */
    public function __construct(array $conditions)
    {
        $this->conditions = $conditions;
    }

    protected function assemble(): void
    {
        $this->add([
            $this->conditions
        ]);
    }
}
