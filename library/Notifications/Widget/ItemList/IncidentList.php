<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseItemList;
use Icinga\Module\Notifications\Common\NoSubjectLink;

class IncidentList extends BaseItemList
{
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => 'incident-list'];

    protected function getItemClass(): string
    {
        return IncidentListItem::class;
    }
}
