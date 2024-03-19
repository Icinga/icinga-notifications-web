<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;

class IncidentContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['minimal', 'incident-contact-list']];

    protected function getItemClass(): string
    {
        return IncidentContactListItem::class;
    }
}
