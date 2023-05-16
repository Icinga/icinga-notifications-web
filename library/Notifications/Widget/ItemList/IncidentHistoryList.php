<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;

class IncidentHistoryList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'minimal'];

    protected function getItemClass(): string
    {
        return IncidentHistoryListItem::class;
    }
}
