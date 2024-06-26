<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Common\NoSubjectLink;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Event;
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

        $this->on(self::ON_ITEM_ADD, function (EventListItem $item, Event $data) {
            ObjectsRendererHook::register($data->object);
        });

        $this->on(self::ON_ASSEMBLED, function () {
            ObjectsRendererHook::load();
        });

        $this->getAttributes()
            ->set('data-icinga-detail-url', (string) Links::event());
    }

    protected function getItemClass(): string
    {
        return EventListItem::class;
    }
}
