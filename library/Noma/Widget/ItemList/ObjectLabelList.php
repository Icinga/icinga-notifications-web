<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseItemList;

class ObjectLabelList extends BaseItemList
{
    protected $defaultAttributes = ['class' => 'incident-object-label-list'];

    protected function getItemClass(): string
    {
        return ObjectLabelListItem::class;
    }
}
