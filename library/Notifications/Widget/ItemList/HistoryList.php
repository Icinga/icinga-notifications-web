<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use Icinga\Module\Notifications\View\NotificationRenderer;
use ipl\Orm\ResultSet;

/**
 * Notification history list with page separators and a Load More link
 *
 * @extends ObjectList<\Icinga\Module\Notifications\Model\NotificationHistory>
 */
class HistoryList extends ObjectList
{
    use LoadMore;

    /**
     * Create a new HistoryList
     *
     * @param ResultSet<\Icinga\Module\Notifications\Model\NotificationHistory> $data
     *   A result set with lookahead enabled
     */
    public function __construct(ResultSet $data)
    {
        parent::__construct($this->getIterator($data), new NotificationRenderer());
    }
}
