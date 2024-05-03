<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Web\Widget\Icon;

class IconBall extends BaseHtmlElement
{
    protected $tag = 'span';

    protected $defaultAttributes = ['class' => ['icon-ball']];

    public function __construct(string $name, ?string $style = 'fa-solid')
    {
        $icon = new Icon($name);
        if ($style !== null) {
            $icon->setStyle($style);
        }

        $this->addHtml($icon);
    }
}
