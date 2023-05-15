<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\LoadMore;
use Icinga\Module\Noma\Common\BaseItemList;
use ipl\Orm\ResultSet;

class EventRuleList extends BaseItemList
{
    use LoadMore;

    protected $defaultAttributes = ['class' => 'event-rule-list'];

    protected function getItemClass(): string
    {
        return EventRuleListItem::class;
    }
}
