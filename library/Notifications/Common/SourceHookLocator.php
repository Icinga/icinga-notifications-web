<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Application\Hook;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Stdlib\Str;

class SourceHookLocator
{
    /**
     * Get the source hook responsible for the given source type
     *
     * Returns `null` if no module providing such a hook is enabled.
     *
     * {@see Hook::assertValidHook()} derives the expected base class of a hook from its name. Since the hook's name
     * carries the source type, a class alias of {@see SourceHook} matching the expected class name is created
     * so the validation passes.
     *
     * @param string $type The source type as stored in the `source` table
     *
     * @return ?SourceHook
     */
    public static function forType(string $type): ?SourceHook
    {
        $name = ucfirst(Str::camel($type));

        $alias = 'Icinga\Module\Notifications\Hook\V2\\' . $name . 'SourceHook';
        if (! interface_exists($alias)) {
            class_alias(SourceHook::class, $alias);
        }

        return Hook::first('Notifications/v2/' . $name . 'Source');
    }
}
