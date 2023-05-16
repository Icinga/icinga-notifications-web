<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseItemList;
use Icinga\Module\Noma\Common\NoSubjectLink;

class IncidentList extends BaseItemList
{
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => 'incident-list'];

    protected function getItemClass(): string
    {
        return IncidentListItem::class;
    }
}
