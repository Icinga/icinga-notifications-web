<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use ipl\Web\Common\BaseItemList;

class EventRuleList extends BaseItemList
{
    use LoadMore;

    protected $defaultAttributes = ['class' => ['action-list', 'event-rule-list']];

    protected function getItemClass(): string
    {
        return EventRuleListItem::class;
    }
}
