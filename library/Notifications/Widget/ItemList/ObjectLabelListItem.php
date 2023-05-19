<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseListItem;
use Icinga\Module\Notifications\Model\SourceObject;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\SourceIcon;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;

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
        $visual->add(
            (new SourceIcon(SourceIcon::SIZE_LARGE))
                ->addHtml($this->item->source->getIcon())
        );
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
