<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Source;
use ipl\Html\BaseHtmlElement;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class SourceListItem extends BaseListItem
{
    /** @var Source */
    protected $item;

    /** @var SourceList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $visual->addHtml($this->item->getIcon());
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link(
            $this->item->name,
            Url::fromPath('notifications/source', ['id' => $this->item->id]),
            ['class' => 'subject']
        ));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->addHtml($this->createTitle());
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->addHtml($this->createHeader());
    }
}
