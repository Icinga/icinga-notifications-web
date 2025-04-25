<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\IncidentContact;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Model\Source;
use ipl\Web\Widget\ItemList;
use ipl\Web\Widget\ListItem;

/**
 * ObjectList
 *
 * Create a list
 *
 * @template Item of Event|Incident|IncidentHistory|IncidentContact|Source|Channel|Contact|Contactgroup|Rule|Schedule
 *
 * @extends ItemList<Item>
 */
class ObjectList extends ItemList
{
    /** @var bool Whether the action-list functionality should be disabled */
    protected $disableActionList = false;

    public function __construct($data, $itemRenderer)
    {
        parent::__construct($data, $itemRenderer);

        $this->getAttributes() // TODO(sd): only required for IncidentHistory, find a better solution
            ->registerAttributeCallback('class', function () {
                return $this->disableActionList ? null : 'action-list';
            });
    }

    /**
     * Set whether the action-list functionality should be disabled
     *
     * @param bool $state
     *
     * @return $this
     */
    public function disableActionList(bool $state = true): self
    {
        $this->disableActionList = $state;

        return $this;
    }

    protected function createListItem(object $data): ListItem
    {
        $item = parent::createListItem($data);

        if (! $this->disableActionList) {
            $item->addAttributes(['data-action-item' => true]);
        }

        return $item;
    }
}
