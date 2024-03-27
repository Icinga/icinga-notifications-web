<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Channel;
use ipl\Html\BaseHtmlElement;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/**
 * Channel item of a channel list. Represents one database row.
 */
class ChannelListItem extends BaseListItem
{
    /** @var Channel The associated list item */
    protected $item;

    /** @var ChannelList The list where the item is part of */
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
            Url::fromPath('notifications/channel', ['id' => $this->item->id]),
            ['class' => 'subject']
        ));
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
