<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;

class ScheduleList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['action-list', 'schedule-list']];

    protected function getItemClass(): string
    {
        return ScheduleListItem::class;
    }
}
