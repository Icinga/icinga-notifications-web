<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Common;

use ipl\Web\Url;

/**
 * This class provides all module related links
 */
abstract class Links
{
    public static function event(int $id): Url
    {
        return Url::fromPath('noma/event', ['id' => $id]);
    }

    public static function events(): Url
    {
        return Url::fromPath('noma/events');
    }
}
