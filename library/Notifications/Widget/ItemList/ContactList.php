<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseItemList;

class ContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'contact-list'];

    protected function getItemClass(): string
    {
        return ContactListItem::class;
    }
}
