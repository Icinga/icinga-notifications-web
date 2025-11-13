<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web;

use ipl\Stdlib\Filter;
use ipl\Web\Filter\Renderer;

class FilterRenderer extends Renderer
{
    protected function renderCondition(Filter\Condition $condition): void
    {
        $value = $condition->getValue();
        if (is_bool($value) && ! $value) {
            $this->string .= '!';
        }

        $this->string .= $condition->getColumn();

        if (is_bool($value)) {
            return;
        }

        $this->string .= match (true) {
            $condition instanceof Filter\Unequal,
                $condition instanceof Filter\Unlike         => '!=',
            $condition instanceof Filter\Equal,
                $condition instanceof Filter\Like           => '=',
            $condition instanceof Filter\GreaterThan        => '>',
            $condition instanceof Filter\LessThan           => '<',
            $condition instanceof Filter\GreaterThanOrEqual => '>=',
            $condition instanceof Filter\LessThanOrEqual    => '<='
        };

        if (is_array($value)) {
            $this->string .= '(' . join('|', $value) . ')';
        } elseif ($value !== null) {
            $this->string .= $value;
        }
    }
}
