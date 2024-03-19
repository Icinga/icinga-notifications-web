<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

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
}
