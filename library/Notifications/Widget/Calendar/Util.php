<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateTime;

final class Util
{
    public static function diffHours(DateTime $from, DateTime $to)
    {
        $diff = $from->diff($to);

        $hours = 0;
        if ($diff->h > 0) {
            $hours += $diff->h;
        }

        if ($diff->i > 0) {
            $hours += $diff->i / 60;
        }

        if ($diff->days > 0) {
            $hours += $diff->days * 24;
        }

        return $hours;
    }

    public static function roundToNearestThirtyMinute(DateTime $time): DateTime
    {
        $hour = (int) $time->format('H');
        $minute = (int) $time->format('i');

        $time = clone $time;
        if ($minute < 15) {
            $time->setTime($hour, 0);
        } elseif ($minute >= 45) {
            $time->setTime($hour + 1, 0);
        } else {
            $time->setTime($hour, 30);
        }

        return $time;
    }
}
