<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Widget\ShowMore;
use ipl\Html\HtmlDocument;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Web\Common\BaseItemList;

/**
 * Lists `incident_history` entries and additionally injects their corresponding source events
 */
class ExtendedIncidentHistoryList extends BaseItemList
{
    /** @var array<string, mixed> The default attributes to set on {@link BaseItemList} objects */
    protected $defaultAttributes = ['class' => 'minimal'];

    /** @var Event Source event which gets tracked during the list assembly */
    private $eventTracker;

    /** @var string Order in which the list gets constructed */
    protected $order = 'desc';

    /**
     * @param Query | ResultSet | iterable<object> $data
     */
    public function __construct($data)
    {
        if ($data instanceof Query) {
            $this->order = $this->calculateListOrder($data);
            $data = $data->execute();
        }

        parent::__construct($data);
    }

    protected function init(): void
    {
        $this->on(BaseItemList::BEFORE_ITEM_ADD, function ($item, $data) {
            /** @var ExtendedIncidentHistoryListItem $item */
            /** @var IncidentHistory $data */
            /** @var Event $event */
            $event = $data->event;
            $itemClass = $this->getItemClass();

            if ($this->eventTracker === null) {
                $this->eventTracker = $event;

                if ($this->order === 'asc' && $event->id !== null) {
                    /** @var ExtendedIncidentHistoryListItem $pseudoItem */
                    $pseudoItem = new $itemClass($this->createPseudoData($event), $this);
                    $this->addHtml($pseudoItem);
                }
            }

            if ($this->eventTracker->id !== $event->id) {
                // source event changed
                if ($this->order === 'desc') {
                    $sourceEvent = $this->eventTracker;
                } else {
                    $sourceEvent = $event;
                }

                if ($sourceEvent->id !== null) {
                    /** @var ExtendedIncidentHistoryListItem $pseudoItem */
                    $pseudoItem = new $itemClass($this->createPseudoData($sourceEvent), $this);
                    $this->addHtml($pseudoItem);
                }

                // update tracker to use the newest source event
                $this->eventTracker = $event;
            }
        });

        if ($this->order === 'desc') {
            $this->on(HtmlDocument::ON_ASSEMBLED, function () {
                if ($this->order === 'desc' && $this->eventTracker !== null && $this->eventTracker->id !== null) {
                    $itemClass = $this->getItemClass();
                    /*
                     * TODO(nc): Find a better way to handle the show more element position when building in
                     *  descending order
                     */
                    $showMore = null;
                    $elements = $this->getContent();
                    if ($elements[sizeof($elements) - 1] instanceof ShowMore) {
                        $showMore = $elements[sizeof($elements) - 1];
                        $this->remove($showMore);
                    }

                    /** @var ExtendedIncidentHistoryListItem $pseudoItem */
                    $pseudoItem = new $itemClass($this->createPseudoData($this->eventTracker), $this);
                    $this->addHtml($pseudoItem);

                    if ($showMore) {
                        $this->addHtml($showMore);
                    }
                }
            });
        }
    }

    /**
     * Calculates what order the list should be displayed
     *
     * @param Query $query
     * @return string
     */
    private function calculateListOrder(Query $query): string
    {
        $orderBy = $query->getOrderBy();
        if ($orderBy === null) {
            $sort = $query->getModel()->getDefaultSort();
            $rules = [];

            if (gettype($sort) === 'array') {
                for ($i = 0; $i < sizeof($sort); ++$i) {
                    $rule = $sort[$i];
                    $rules[] = array_values(explode(' ', trim($rule)));
                }
                $orderBy = $rules;
            } else {
                $orderBy = array_values(explode(' ', trim($sort)));
            }
        }

        return $orderBy[0][1];
    }

    /**
     * Returns the item class from the entries of the list
     *
     * @return string
     */
    protected function getItemClass(): string
    {
        return ExtendedIncidentHistoryListItem::class;
    }

    protected function createPseudoData(Event $event): IncidentHistory
    {
        $obj = new class extends IncidentHistory {
            /** @var bool */
            protected $isSourceEvent = true;
        };
        $obj->id = -1;
        $obj->incident_id = -1;
        $obj->event_id = $event->id;
        $obj->time = $event->time;
        $obj->type = $event->type;
        $obj->message = $event->message;
        $obj->event = $event;
        return $obj;
    }
}
