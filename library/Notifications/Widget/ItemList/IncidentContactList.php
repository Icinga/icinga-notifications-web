<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;

class IncidentContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['minimal', 'incident-contact-list']];

    protected function getItemClass(): string
    {
        return IncidentContactListItem::class;
    }
}
