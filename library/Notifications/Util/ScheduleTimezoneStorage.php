<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Util;

use Icinga\Exception\ProgrammingError;

/**
 * Storage to store display and schedule timezones
 */
class ScheduleTimezoneStorage
{
    protected static ?string $scheduleTimezone;

    /**
     * Set the schedule timezone
     *
     * @param string $timezone The timezone identifier (e.g. 'Europe/Berlin')
     *
     * @return void
     */
    public static function setScheduleTimezone(string $timezone): void
    {
        static::$scheduleTimezone = $timezone;
    }

    /**
     * Get the schedule timezone
     *
     * @return string
     *
     * @throws ProgrammingError In case the schedule timezone is not set
     */
    public static function getScheduleTimezone(): string
    {
        return static::$scheduleTimezone ?? throw new ProgrammingError('Schedule timezone has to be set first');
    }
}
