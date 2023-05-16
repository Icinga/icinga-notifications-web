<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseListItem;
use Icinga\Module\Noma\Model\Channel;
use ipl\Html\BaseHtmlElement;
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
        if ($this->item->type === 'email') {
            $visual->addHtml(new Icon('envelope'));
        } elseif ($this->item->type === 'rocketchat') {
            $visual->addHtml(new Icon('comment-dots'));
        }
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link(
            $this->item->name,
            Url::fromPath('noma/channel', ['id' => $this->item->id]),
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
