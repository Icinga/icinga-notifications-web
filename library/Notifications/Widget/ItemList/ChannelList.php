<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;

/**
 * A channel list
 */
class ChannelList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'channel-list'];

    protected function getItemClass(): string
    {
        return ChannelListItem::class;
    }
}
