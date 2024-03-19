<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Common\BaseItemList;
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
