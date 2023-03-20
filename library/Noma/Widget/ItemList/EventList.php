<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseItemList;

class EventList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'event-list'];

    protected function getItemClass(): string
    {
        return EventListItem::class;
    }
}
