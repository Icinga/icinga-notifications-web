<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Web\Widget\StateBall;

class FlowLine extends BaseHtmlElement
{
    protected $tag = 'div';

    public function getRightArrow()
    {
        $this->setAttributes(['class' => 'right-arrow']);

        return $this;
    }

    public function getHorizontalLine()
    {
        $this->setAttributes(['class' => 'horizontal-line']);

        return $this;
    }

    public function getVerticalLine()
    {
        $this->setAttributes(['class' => 'vertical-line']);

        return $this;
    }
}
