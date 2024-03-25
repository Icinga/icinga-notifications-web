<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Web\Widget\Icon;

class CustomIcon extends BaseHtmlElement
{
    protected $tag = 'span';

    protected $defaultAttributes = ['class' => ['custom-icon']];

    public function __construct(string $icon, string $family = 'fa-regular')
    {
        $icon = trim($icon) ?: '';

        $i = (new Icon($icon))->setStyle($family);
        $this->addHtml($i);
    }
}
