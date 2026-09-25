<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use InvalidArgumentException;
use ipl\Web\Url;

/**
 * This class provides all module related links
 */
abstract class Links
{
    public static function sourceAdd(): Url
    {
        return Url::fromPath('notifications/sources/add');
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

    public static function contactAdd(): Url
    {
        return Url::fromPath('notifications/contacts/add');
    }

    public static function channels(): Url
    {
        return Url::fromPath('notifications/channels');
    }

    public static function channel(int $id): Url
    {
        return Url::fromPath('notifications/channel', ['id' => $id]);
    }

    public static function channelAdd(): Url
    {
        return Url::fromPath('notifications/channels/add');
    }

    public static function eventRules(): Url
    {
        return Url::fromPath('notifications/event-rules');
    }

    public static function eventRule(int $id): Url
    {
        return Url::fromPath('notifications/event-rule', ['id' => $id]);
    }

    public static function eventRuleFilter(int $id): Url
    {
        return Url::fromPath('notifications/event-rule/search-editor', ['id' => $id]);
    }

    public static function escalationEdit(int $id): Url
    {
        return Url::fromPath('notifications/rule-escalation/edit', ['id' => $id]);
    }

    public static function escalationAdd(int $ruleId, int $position): Url
    {
        return Url::fromPath('notifications/rule-escalations/add', [
            'rule' => $ruleId,
            'position' => $position
        ]);
    }

    public static function notificationRecipientsEdit(int $id): Url
    {
        return Url::fromPath('notifications/notification-rule/edit', ['id' => $id]);
    }

    public static function notificationRecipientsAdd(int $ruleId, int $position): Url
    {
        if ($position !== 0) {
            throw new InvalidArgumentException('Position must be 0 when adding a new notification recipient');
        }

        return Url::fromPath('notifications/notification-rule/add', [
            'rule' => $ruleId
        ]);
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

    public static function rotationSettings(int $id): Url
    {
        return Url::fromPath('notifications/schedule/edit-rotation', ['id' => $id]);
    }

    public static function moveRotation(int $scheduleId): Url
    {
        return Url::fromPath('notifications/schedule/move-rotation', ['schedule' => $scheduleId]);
    }
}
