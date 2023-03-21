<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\LoadMore;
use Icinga\Module\Noma\Common\BaseItemList;
use ipl\Orm\ResultSet;

class EventList extends BaseItemList
{
    use LoadMore;

    protected $defaultAttributes = ['class' => 'event-list'];

    /** @var ResultSet */
    protected $data;

    public function __construct(ResultSet $data)
    {
        parent::__construct($data);
    }

    protected function init(): void
    {
        $this->data = $this->getIterator($this->data);
    }

    protected function getItemClass(): string
    {
        return EventListItem::class;
    }
}
