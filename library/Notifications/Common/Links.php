<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Common;

use ipl\Web\Url;

/**
 * This class provides all module related links
 */
abstract class Links
{
    public static function event(int $id): Url
    {
        return Url::fromPath('notifications/event', ['id' => $id]);
    }

    public static function events(): Url
    {
        return Url::fromPath('notifications/events');
    }

    public static function incidents(): Url
    {
        return Url::fromPath('notifications/incidents');
    }

    public static function incident(int $id): Url
    {
        return Url::fromPath('notifications/incident', ['id' => $id]);
    }

    public static function contact(int $id): Url
    {
        return Url::fromPath('notifications/contact', ['id' => $id]);
    }

    public static function eventRules(): Url
    {
        return Url::fromPath('notifications/event-rules');
    }

    public static function eventRule(int $id): Url
    {
        return Url::fromPath('notifications/event-rule', ['id' => $id]);
    }
}
