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

    public const MANAGE = 'circle-check';

    public const UNMANAGE = 'circle-xmark';

    public const UNSUBSCRIBED = 'circle-xmark';

    public const SUBSCRIBED = 'circle-check';

    public const TRIGGERED = 'square-up-right';

    public const NOTIFIED = 'paper-plane';

    public const SUBSCRIBE = 'sync-alt';
}
