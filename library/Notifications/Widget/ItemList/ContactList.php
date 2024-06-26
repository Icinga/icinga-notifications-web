<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use ipl\Web\Common\BaseItemList;

class ContactList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['action-list', 'contact-list']];

    protected function getItemClass(): string
    {
        return ContactListItem::class;
    }

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-icinga-detail-url', (string) Links::contact());
    }
}
