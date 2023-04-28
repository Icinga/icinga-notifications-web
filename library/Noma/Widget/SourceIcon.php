<?php

namespace Icinga\Module\Noma\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Web\Widget\StateBall;

class SourceIcon extends BaseHtmlElement
{
    const SIZE_TINY = StateBall::SIZE_TINY;
    const SIZE_SMALL = StateBall::SIZE_SMALL;
    const SIZE_MEDIUM = StateBall::SIZE_MEDIUM;
    const SIZE_MEDIUM_LARGE = StateBall::SIZE_MEDIUM;
    const SIZE_BIG = StateBall::SIZE_BIG;
    const SIZE_LARGE = StateBall::SIZE_LARGE;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'source-icon'];

    public function __construct(string $size = self::SIZE_MEDIUM)
    {
        $size = trim($size) ?: self::SIZE_MEDIUM;

        $this->getAttributes()->add('class', "ball-size-$size");
    }
}
