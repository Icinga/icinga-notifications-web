<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Web;

use ipl\Stdlib\Filter;
use ipl\Web\Filter\Renderer;

class FilterRenderer extends Renderer
{
    protected function renderCondition(Filter\Condition $condition)
    {
        $value = $condition->getValue();
        if (is_bool($value) && ! $value) {
            $this->string .= '!';
        }

        $this->string .= $condition->getColumn();

        if (is_bool($value)) {
            return;
        }

        switch (true) {
            case $condition instanceof Filter\Unequal:
            case $condition instanceof Filter\Unlike:
                $this->string .= '!=';
                break;
            case $condition instanceof Filter\Equal:
            case $condition instanceof Filter\Like:
                $this->string .= '=';
                break;
            case $condition instanceof Filter\GreaterThan:
                $this->string .= '>';
                break;
            case $condition instanceof Filter\LessThan:
                $this->string .= '<';
                break;
            case $condition instanceof Filter\GreaterThanOrEqual:
                $this->string .= '>=';
                break;
            case $condition instanceof Filter\LessThanOrEqual:
                $this->string .= '<=';
                break;
        }

        if (is_array($value)) {
            $this->string .= '(' . join('|', $value) . ')';
        } elseif ($value !== null) {
            $this->string .= $value;
        }
    }
}
