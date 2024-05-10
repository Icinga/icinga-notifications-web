<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

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

    public static function contacts(): Url
    {
        return Url::fromPath('notifications/contacts');
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

    public static function schedules(): Url
    {
        return Url::fromPath('notifications/schedules');
    }

    public static function schedule(int $id): Url
    {
        return Url::fromPath('notifications/schedule', ['id' => $id]);
    }

    public static function scheduleAdd(): Url
    {
        return Url::fromPath('notifications/schedule/add');
    }

    public static function scheduleSettings(int $id): Url
    {
        return Url::fromPath('notifications/schedule/settings', ['id' => $id]);
    }

    public static function contactGroups(): Url
    {
        return Url::fromPath('notifications/contact-groups');
    }

    public static function contactGroupsAdd(): Url
    {
        return Url::fromPath('notifications/contact-groups/add');
    }

    public static function contactGroupsSuggestMember(): Url
    {
        return Url::fromPath('notifications/contact-groups/suggest-member');
    }

    public static function contactGroup(int $id): Url
    {
        return Url::fromPath('notifications/contact-group', ['id' => $id]);
    }

    public static function contactGroupEdit(int $id): Url
    {
        return Url::fromPath('notifications/contact-group/edit', ['id' => $id]);
    }

    public static function rotationAdd(int $scheduleId): Url
    {
        return Url::fromPath('notifications/schedule/add-rotation', ['schedule' => $scheduleId]);
    }

    public static function rotationSettings(int $id, int $scheduleId): Url
    {
        return Url::fromPath('notifications/schedule/edit-rotation', ['id' => $id, 'schedule' => $scheduleId]);
    }

    public static function moveRotation(): Url
    {
        return Url::fromPath('notifications/schedule/move-rotation');
    }
}
