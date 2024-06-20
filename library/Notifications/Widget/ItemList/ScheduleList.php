<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use ipl\Web\Common\BaseItemList;

class ScheduleList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['action-list', 'schedule-list']];

    protected function getItemClass(): string
    {
        return ScheduleListItem::class;
    }

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-icinga-detail-url', (string) Links::schedule());
    }
}
