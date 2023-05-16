<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;

class ObjectLabelList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['minimal', 'incident-object-label-list']];

    protected function getItemClass(): string
    {
        return ObjectLabelListItem::class;
    }
}
