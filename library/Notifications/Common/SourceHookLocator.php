<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Application\Hook;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Stdlib\Str;

class SourceHookLocator
{
    public static function forType(string $type): ?SourceHook
    {
        $name = ucfirst(Str::camel($type));

        /** Required for {@see Hook::assertValidHook()} to pass */
        $alias = 'Icinga\Module\Notifications\Hook\V2\\' . $name . 'SourceHook';
        if (! interface_exists($alias)) {
            class_alias(SourceHook::class, $alias);
        }

        return Hook::first('Notifications/v2/' . $name . 'Source');
    }
}
