<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;

class ContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'contact-list'];

    protected function getItemClass(): string
    {
        return ContactListItem::class;
    }
}
