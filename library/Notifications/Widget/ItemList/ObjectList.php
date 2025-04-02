<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Event;
use ipl\Web\Widget\ItemList;
use ipl\Web\Widget\ListItem;

/**
 * ObjectList
 *
 * Create a list
 *
 * @template Item of Event
 *
 * @extends ItemList<Item>
 */
class ObjectList extends ItemList
{
    protected $defaultAttributes = ['class' => 'action-list'];

    protected function createListItem(object $data): ListItem
    {
        return parent::createListItem($data)
            ->addAttributes(['data-action-item' => true]);
    }
}
