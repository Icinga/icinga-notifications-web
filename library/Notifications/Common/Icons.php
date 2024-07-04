<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

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

    public const SEVERITY_OK = 'heart';

    public const SEVERITY_CRIT = 'circle-exclamation';

    public const SEVERITY_WARN = 'triangle-exclamation';

    public const SEVERITY_ERR = 'circle-xmark';

    public const SEVERITY_DEBUG = 'bug-slash';

    public const SEVERITY_INFO = 'circle-info';

    public const SEVERITY_ALERT = 'bell';

    public const SEVERITY_EMERG = 'bullhorn';

    public const SEVERITY_NOTICE = 'envelope';
}
