<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\Common\BaseItemList;
use Icinga\Module\Notifications\Common\NoSubjectLink;
use Icinga\Module\Notifications\Hook\EventsObjectsInfoHook;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\ValidHtml;
use ipl\Orm\ResultSet;

class EventList extends BaseItemList
{
    use LoadMore;
    use NoSubjectLink;

    protected $defaultAttributes = ['class' => 'event-list'];

    /** @var ResultSet */
    protected $data;

    /** @var array<string, array<string, array<string, string>>> Object ID tags for each source */
    public $objectIdTagsCache = [];

    /** @var array<string, ValidHtml> Object display names obtained from the corresponding web modules of the sources */
    public $renderedObjectDisplayNames = [];

    public function __construct(ResultSet $data)
    {
        parent::__construct($data);
    }

    protected function init(): void
    {
        $this->data = $this->getIterator($this->data);

        $this->on(self::ON_ITEM_ADD, function (EventListItem $item, Event $data) {
            /** @var Objects $obj */
            $obj = $data->object;
            /** @var Source $src */
            $src = $obj->source;
            $this->objectIdTagsCache[$src->type][$obj->id] = $obj->id_tags;
        });

        $this->on(self::ON_ASSEMBLED, function () {
            $this->renderedObjectDisplayNames = EventsObjectsInfoHook::getObjectsDisplayNames(
                $this->objectIdTagsCache
            );
        });
    }

    protected function getItemClass(): string
    {
        return EventListItem::class;
    }

    /**
     * Get the rendered object display name for the given object ID
     *
     * @param string $objectID
     *
     * @return ?ValidHtml
     */
    public function getRenderedObjectDisplayName(string $objectID): ?ValidHtml
    {
        return $this->renderedObjectDisplayNames[$objectID] ?? null;
    }
}
