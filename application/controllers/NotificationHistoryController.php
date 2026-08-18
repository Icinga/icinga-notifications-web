<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use Icinga\Module\Notifications\Widget\Detail\NotificationHistoryDetail;
use ipl\Html\Attributes;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class NotificationHistoryController extends CompatController
{
    use Auth;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Notification History'));

        $id = $this->params->getRequired('id');

        $query = NotificationHistory::on(Database::get())
            ->with([
                'contact',
                'contactgroup',
                'schedule',
                'channel',
                'incident',
                'incident_history',
                'incident.object',
                'incident.object.source'
            ])
            ->withColumns('incident.object.id_tags')
            ->filter(Filter::equal('id', $id));

        $this->applyRestrictions($query);

        /** @var NotificationHistory $notificationHistory */
        $notificationHistory = $query->first();
        if ($notificationHistory === null) {
            $this->httpNotFound(t('Notification History not found'));
        }

        $this->controls->addAttributes(Attributes::create(['class' => 'notification-history-detail']));
        $this->addControl(new ObjectHeader($notificationHistory));
        $this->addContent(new NotificationHistoryDetail($notificationHistory));
    }
}
