<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;

class IncidentHistoryList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'minimal'];

    protected function getItemClass(): string
    {
        return IncidentHistoryListItem::class;
    }
}
