<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Common;

final class Icons
{
    private function __construct()
    {
    }

    public const WARNING = 'exclamation-triangle';

    public const OK = 'circle-check';

    public const CRITICAL = 'circle-exclamation';

    public const ERROR = 'circle-xmark';

    public const USER = 'user';

    public const USER_MANAGER = 'user-tie';

    public const CLOSED = 'circle-check';

    public const OPENED = 'sun';

    public const MANAGE = 'bell';

    public const UNMANAGE = 'bell-slash';

    public const UNSUBSCRIBED = 'comment-slash';

    public const SUBSCRIBED = 'comment';

    public const TRIGGERED = 'square-up-right';

    public const NOTIFIED = 'paper-plane';

    public const RULE_MATCHED = 'filter';
}
