<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Common\BaseItemList;
use Icinga\Module\Notifications\Common\NoSubjectLink;
use ipl\Orm\ResultSet;

class EventList extends BaseItemList
{
    use LoadMore;
    use NoSubjectLink;

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