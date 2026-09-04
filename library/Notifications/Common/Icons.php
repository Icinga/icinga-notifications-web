<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Widget\IconBall;
use UnhandledMatchError;
use UnitEnum;

final class Icons
{
    private function __construct()
    {
    }

    public const WARNING = 'triangle-exclamation';

    public const OK = 'circle-check';

    public const CRITICAL = 'circle-exclamation';

    public const ERROR = 'circle-xmark';

    public const USER = 'user';

    public const USER_MANAGER = 'user-tie';

    public const CLOSED = 'check';

    public const OPENED = 'sun';

    public const MANAGE = 'bell';

    public const UNMANAGE = 'bell-slash';

    public const UNSUBSCRIBED = 'comment-slash';

    public const SUBSCRIBED = 'comment';

    public const TRIGGERED = 'square-up-right';

    public const NOTIFIED = 'paper-plane';

    public const RULE_MATCHED = 'filter';

    public const UNDEFINED = 'notdef';

    public const ACKNOWLEDGED = 'check';

    public const UNACKNOWLEDGED = 'xmark';

    public const DOWNTIME = 'plug';

    public const FLAPPING = 'bolt';

    public const INCIDENT_AGE = 'hourglass-end';

    public const CUSTOM = 'message';

    public const MUTE = 'volume-xmark';

    public const UNMUTE = 'volume-high';

    public const CONTACTGROUP = 'users';

    public const SCHEDULE = 'calendar';

    /**
     * Get the icon for the given enum
     *
     * @param UnitEnum $enum
     *
     * @return IconBall
     *
     * @throws UnhandledMatchError If no icon is known for the given enum
     */
    public static function forEnum(UnitEnum $enum): IconBall
    {
        return new IconBall(match ($enum) {
            IncidentHistoryType::OPENED => Icons::OPENED,
            IncidentHistoryType::MUTED => Icons::MUTE,
            IncidentHistoryType::UNMUTED => Icons::UNMUTE,
            IncidentHistoryType::CLOSED => Icons::CLOSED,
            IncidentHistoryType::RULE_MATCHED => Icons::RULE_MATCHED,
            IncidentHistoryType::ESCALATION_TRIGGERED => Icons::TRIGGERED,
            IncidentHistoryType::NOTIFIED => Icons::NOTIFIED
        });
    }
}
