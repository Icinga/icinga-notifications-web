<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\View\EventRenderer;
use ipl\Orm\Model;
use ipl\Orm\ResultSet;

/**
 * LoadMoreObjectList
 *
 * Create a list of objects with Load more link
 *
 * @template Item of Event
 *
 * @extends ObjectList<Item>
 */
class LoadMoreObjectList extends ObjectList
{
    use LoadMore;

    public function __construct(ResultSet $data)
    {
        parent::__construct($data, function (Model $item) {
            if ($item instanceof Event) {
                return new EventRenderer();
            }

            throw new NotImplementedError('Not implemented');
        });

        $this->data = $this->getIterator($data);
    }
}
