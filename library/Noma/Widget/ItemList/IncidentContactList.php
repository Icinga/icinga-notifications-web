<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseItemList;

class IncidentContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'incident-contact-list'];

    protected function getItemClass(): string
    {
        return IncidentContactListItem::class;
    }
}
