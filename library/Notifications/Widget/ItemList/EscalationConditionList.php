<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;

class EscalationConditionList extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'options'];

    protected $tag = 'ul';

    /** @var EscalationConditionListItem[] Condition list items */
    protected $conditions;

    /**
     * Create conditions list of the escalation
     *
     * @param EscalationConditionListItem[] $conditions
     */
    public function __construct(array $conditions)
    {
        $this->conditions = $conditions;
    }

    protected function assemble(): void
    {
        $removedPosition = null;
        foreach ($this->conditions as $position => $condition) {
            if ($condition->hasBeenRemoved()) {
                $removedPosition = $position;

                continue;
            }

            if ($removedPosition) {
                $condition->setPosition($position - 1);
            }
        }

        foreach ($this->conditions as $position => $condition) {
            if ($position !== $removedPosition) {
                $this->addHtml($condition);
            }
        }
    }
}