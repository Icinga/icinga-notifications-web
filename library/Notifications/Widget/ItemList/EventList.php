<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Common\NoSubjectLink;
use ipl\Orm\ResultSet;
use ipl\Web\Common\BaseItemList;

class EventList extends BaseItemList
{
    use LoadMore;
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => ['action-list', 'event-list']];

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
