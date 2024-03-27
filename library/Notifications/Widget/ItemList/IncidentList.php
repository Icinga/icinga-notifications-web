<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use ipl\Web\Common\BaseItemList;
use Icinga\Module\Notifications\Common\NoSubjectLink;

class IncidentList extends BaseItemList
{
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => ['action-list', 'incident-list']];

    protected function getItemClass(): string
    {
        return IncidentListItem::class;
    }
}
