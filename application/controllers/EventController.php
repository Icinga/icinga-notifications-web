<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Widget\Detail\EventDetail;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
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
            ->with(['object', 'object.source', 'incident', 'incident.object', 'incident.object.source'])
            ->withColumns(['object.id_tags', 'incident.object.id_tags'])
            ->filter(Filter::equal('event.id', $id));

        // ipl-orm doesn't detect dependent joins yet
        $query->getWith()['event.incident.object']->setJoinType('LEFT');

        $this->applyRestrictions($query);

        /** @var Event $event */
        $event = $query->first();
        if ($event === null) {
            $this->httpNotFound(t('Event not found'));
        }

        $this->addControl(new ObjectHeader($event));

        $this->controls->addAttributes(['class' => 'event-detail']);

        $this->addContent(new EventDetail($event));
    }
}
