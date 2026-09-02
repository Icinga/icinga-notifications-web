<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Stdlib\Str;
use Throwable;

class SourceHookLocator
{
    /** @var array<string, string> */
    private static array $labels = [];

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

        $alias = 'Icinga\\Module\\Notifications\\Hook\\V2\\' . $name . 'SourceHook';
        if (! interface_exists($alias)) {
            class_alias(SourceHook::class, $alias);
        }

        return Hook::first('Notifications\\V2\\' . $name . 'Source');
    }

    /**
     * Get the label for the given source type
     *
     * @param string $type
     *
     * @return string
     */
    public static function labelFor(string $type): string
    {
        if (array_key_exists($type, self::$labels)) {
            return self::$labels[$type];
        }

        $label = $type;

        $hook = static::forType($type);
        if ($hook !== null) {
            try {
                $label = $hook->getSourceLabel();
            } catch (Throwable $e) {
                Logger::error(
                    'Failed to retrieve the label from source hook "%s" for type "%s": %s',
                    $hook::class,
                    $type,
                    $e
                );
            }
        }

        self::$labels[$type] = $label;

        return $label;
    }
}
