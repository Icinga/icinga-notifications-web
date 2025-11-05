<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\DetailActions;
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
use ipl\Stdlib\Filter;
use ipl\Web\Url;
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
    use DetailActions;

    protected function init(): void
    {
        $this->initializeDetailActions();
    }

    protected function createListItem(object $data): ListItem
    {
        $item = parent::createListItem($data);

        if (! $this->getDetailActionsDisabled()) {
            $link = match (true) {
                $data instanceof Event          => Url::fromPath('notifications/event'),
                $data instanceof Incident       => Url::fromPath('notifications/incident'),
                $data instanceof Schedule       => Url::fromPath('notifications/schedule'),
                $data instanceof Rule           => Url::fromPath('notifications/event-rule'),
                $data instanceof Contact        => Url::fromPath('notifications/contact'),
                $data instanceof Contactgroup   => Url::fromPath('notifications/contact-group'),
                $data instanceof Channel        => Url::fromPath('notifications/channel'),
                $data instanceof Source         => Url::fromPath('notifications/source'),
                default                         => null
            };

            if ($link !== null) {
                $this->setDetailUrl($link);
                $this->addDetailFilterAttribute($item, Filter::equal('id', $data->id));
            }
        }

        return $item;
    }
}
