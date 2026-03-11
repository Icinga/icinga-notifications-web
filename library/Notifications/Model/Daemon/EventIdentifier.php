<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model\Daemon;

/**
 * TODO(nc): Replace with proper Enum once the lowest supported PHP version raises to 8.1
 */
final class EventIdentifier
{
    /**
     * notifications
     */
    public const ICINGA2_NOTIFICATION = 'icinga2.notification';
}
