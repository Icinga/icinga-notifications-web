<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Util;

use DateTimeZone;

/**
 * Storage to store display and schedule timezones
 */
class ScheduleTimezoneStorage
{
    protected static ?DateTimeZone $displayTimezone;

    protected static ?DateTimeZone $scheduleTimezone;

    /**
     * Set the display timezone
     *
     * @param DateTimeZone|string $timezone The timezone identifier (e.g. 'Europe/Berlin')
     *
     * @return void
     */
    public static function setDisplayTimezone(DateTimeZone|string $timezone): void
    {
        if ($timezone instanceof DateTimeZone) {
            static::$displayTimezone = $timezone;
        } else {
            static::$displayTimezone = new DateTimeZone($timezone);
        }
    }

    /**
     * Get the display timezone
     *
     * @return DateTimeZone
     */
    public static function getDisplayTimezone(): DateTimeZone
    {
        return static::$displayTimezone ?? static::getScheduleTimezone();
    }

    /**
     * Set the schedule timezone
     *
     * @param DateTimeZone|string $timezone The timezone identifier (e.g. 'Europe/Berlin')
     *
     * @return void
     */
    public static function setScheduleTimezone(DateTimeZone|string $timezone): void
    {
        if ($timezone instanceof DateTimeZone) {
            static::$scheduleTimezone = $timezone;
        } else {
            static::$scheduleTimezone = new DateTimeZone($timezone);
        }
    }

    /**
     * Get the schedule timezone
     *
     * @return DateTimeZone
     */
    public static function getScheduleTimezone(): DateTimeZone
    {
        return static::$scheduleTimezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Get whether the display and schedule timezones differ
     *
     * @return bool Whether the display and schedule timezones differ
     */
    public static function differ(): bool
    {
        return static::getDisplayTimezone()->getName() !== static::getScheduleTimezone()->getName();
    }
}
