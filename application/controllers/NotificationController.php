<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use Icinga\Module\Notifications\Widget\Detail\NotificationDetail;
use ipl\Html\Attributes;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class NotificationController extends CompatController
{
    use Auth;

    public function init()
    {
        $this->assertPermission('notifications/view/history');

        parent::init();
    }

    public function indexAction(): void
    {
        $this->addTitleTab(t('Notification'));

        $id = $this->params->getRequired('id');

        $query = NotificationHistory::on(Database::get())
            ->with([
                'contact',
                'contactgroup',
                'schedule',
                'channel',
                'incident',
                'object',
            ])
            ->withColumns(['object.sources', 'channel_label' => 'channel.available_channel_type.name'])
            ->filter(Filter::equal('id', $id));

        $this->applyRestrictions($query);

        /** @var NotificationHistory $notificationHistory */
        $notificationHistory = $query->first();
        if ($notificationHistory === null) {
            $this->httpNotFound(t('Notification not found'));
        }

        $this->controls->addAttributes(Attributes::create(['class' => 'notification-history-detail']));
        $this->addControl(new ObjectHeader($notificationHistory));
        $this->addContent(new NotificationDetail($notificationHistory));
    }
}
