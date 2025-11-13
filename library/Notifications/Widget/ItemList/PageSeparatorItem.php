<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;

class PageSeparatorItem extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'list-item page-separator'];

    /** @var int */
    protected int $pageNumber;

    /** @var string */
    protected $tag = 'li';

    public function __construct(int $pageNumber)
    {
        $this->pageNumber = $pageNumber;
    }

    protected function assemble(): void
    {
        $this->add(Html::tag(
            'a',
            [
                'id' => 'page-' . $this->pageNumber,
                'data-icinga-no-scroll-on-focus' => true
            ],
            $this->pageNumber
        ));
    }
}
