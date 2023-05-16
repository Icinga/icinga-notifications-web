<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

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

    public static function incidents(): Url
    {
        return Url::fromPath('noma/incidents');
    }

    public static function incident(int $id): Url
    {
        return Url::fromPath('noma/incident', ['id' => $id]);
    }

    public static function contact(int $id): Url
    {
        return Url::fromPath('noma/contact', ['id' => $id]);
    }

    public static function eventRules(): Url
    {
        return Url::fromPath('noma/event-rules');
    }

    public static function eventRule(int $id): Url
    {
        return Url::fromPath('noma/event-rule', ['id' => $id]);
    }
}
