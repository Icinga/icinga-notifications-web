<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Util;

use DateTime;
use DateTimeZone;

/**
 * Factory to create DateTime objects for schedules in a specific timezone
 */
class ScheduleDateTimeFactory
{
    protected static ?DateTimeZone $timezone;

    /**
     * Set the display timezone for the schedule
     *
     * @param DateTimeZone|string $timezone The timezone identifier (e.g. 'Europe/Berlin')
     *
     * @return void
     */
    public static function setDisplayTimezone(DateTimeZone|string $timezone): void
    {
        if ($timezone instanceof DateTimeZone) {
            static::$timezone = $timezone;
        } else {
            static::$timezone = new DateTimeZone($timezone);
        }
    }

    /**
     * Get the display timezone for the schedule
     *
     * @return DateTimeZone
     */
    public static function getDisplayTimezone(): DateTimeZone
    {
        return static::$timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Create a DateTime object in the schedule's timezone
     *
     * @param string $datetime The datetime string (default is 'now')
     *
     * @return DateTime
     */
    public static function createDateTime(string $datetime = 'now'): DateTime
    {
        return new DateTime($datetime, static::getDisplayTimezone());
    }
}
