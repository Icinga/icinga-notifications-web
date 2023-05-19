<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;

class EmptyState extends BaseHtmlElement
{
    /** @var mixed Content */
    protected $content;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'empty-state'];

    public function __construct($content)
    {
        $this->content = $content;
    }

    protected function assemble()
    {
        $this->add($this->content);
    }
}
