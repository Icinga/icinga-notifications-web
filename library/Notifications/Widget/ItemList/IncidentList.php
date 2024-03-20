<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Incident;
use ipl\Web\Common\BaseItemList;
use Icinga\Module\Notifications\Common\NoSubjectLink;

class IncidentList extends BaseItemList
{
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => ['action-list', 'incident-list']];

    protected function init(): void
    {
        $this->on(self::ON_ITEM_ADD, function (IncidentListItem $item, Incident $data) {
             ObjectsRendererHook::register($data->object);
        });

        $this->on(self::ON_ASSEMBLED, function () {
            ObjectsRendererHook::load();
        });
    }

    protected function getItemClass(): string
    {
        return IncidentListItem::class;
    }
}
