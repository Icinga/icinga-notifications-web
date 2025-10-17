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
     * Set the display timezone
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
     * Get the display timezone
     *
     * @return DateTimeZone
     */
    public static function getDisplayTimezone(): DateTimeZone
    {
        return static::$timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Create a DateTime object in the schedule timezone
     *
     * @param string $datetime The datetime string (default is 'now')
     *
     * @return DateTime
     */
    public static function createDateTime(string $datetime = 'now'): DateTime
    {
        return new DateTime($datetime, static::getDisplayTimezone());
    }

    /**
     * Create a DateTime object from a timestamp in the schedule timezone
     *
     * @param int $timestamp The unix timestamp to create the DateTime object from
     *
     * @return DateTime
     */
    public static function createDateTimeFromTimestamp(int $timestamp): DateTime
    {
        return (new DateTime('@' . $timestamp))->setTimezone(static::getDisplayTimezone());
    }
}
