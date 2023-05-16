<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;

class ContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'contact-list'];

    protected function getItemClass(): string
    {
        return ContactListItem::class;
    }
}
