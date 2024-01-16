<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;

class SourceList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'action-list'];

    protected function getItemClass(): string
    {
        return SourceListItem::class;
    }
}
