<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use ArrayObject;
use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Event;
use Icinga\Module\Noma\Widget\Detail\EventDetail;
use Icinga\Module\Noma\Widget\ItemList\EventList;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class EventController extends CompatController
{
    use Auth;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Event'));

        $id = $this->params->getRequired('id');

        $query = Event::on(Database::get())
            ->with(['source', 'object', 'source_object', 'incident', 'incident.object'])
            ->filter(Filter::equal('event.id', $id));

        // ipl-orm doesn't detect dependent joins yet
        $query->getWith()['event.incident.object']->setJoinType('LEFT');

        $this->applyRestrictions($query);

        /** @var Event $event */
        $event = $query->first();
        if ($event === null) {
            $this->httpNotFound(t('Event not found'));
        }

        $this->addControl(
            (new EventList(new ResultSet(new ArrayObject([$event]))))
                ->setPageSize(1)
                ->setNoSubjectLink()
        );

        $this->controls->addAttributes(['class' => 'event-detail']);

        $this->addContent(new EventDetail($event));
    }
}
