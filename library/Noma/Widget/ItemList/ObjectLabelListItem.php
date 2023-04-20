<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseListItem;
use Icinga\Module\Noma\Model\SourceObject;
use Icinga\Module\Noma\Widget\EventSourceBadge;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;

/**
 * Object label item of a object label list. Represents one database row.
 */
class ObjectLabelListItem extends BaseListItem
{
    /** @var SourceObject The associated list item */
    protected $item;

    /** @var ObjectLabelList The list where the item is part of */
    protected $list;

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $visual->add($this->item->source->getIcon());
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->add(Html::tag('span', ['class' => 'subject'], $this->item->name));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }
}
