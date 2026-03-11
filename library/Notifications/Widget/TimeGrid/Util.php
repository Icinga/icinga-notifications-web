<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateTime;
use InvalidArgumentException;

final class Util
{
    /** @var array<string, array{0: int, 1: int}> */
    private static $entryColors = [];

    public static function diffHours(DateTime $from, DateTime $to)
    {
        $diff = $from->diff($to);
        if ($diff->invert) {
            throw new InvalidArgumentException('The end date must be after the start date');
        }

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

    /**
     * Calculate a color for an entry based on the given text
     *
     * @param string $text
     * @param int<0, 100> $transparency
     *
     * @return string A CSS color definition
     */
    public static function calculateEntryColor(string $text, int $transparency): string
    {
        if (! isset(self::$entryColors[$text])) {
            // Get a representation of the attendee's name suitable for conversion to a decimal
            // TODO: There are how million colors in sRGB? Then why not use this as max value and ensure a good spread?
            //       Hashes always have a high number, so the reason why we use the remainder of the modulo operation
            //       below makes somehow sense, though it limits the variation to 360 colors which is not good enough.
            //       The saturation makes it more diverse, but only by a factor of 3. So essentially there are 360 * 3
            //       colors. By far lower than the 16.7 million colors in sRGB. But of course, we need distinct colors
            //       so if 500 thousand colors of these 16.7 millions are so similar that we can't distinguish them,
            //       there's no need for such a high variance. Hence we'd still need to partition the colors in a way
            //       that they are distinct enough.
            $hash = hexdec(substr(hash('sha256', $text), 28, 8));
            // Limit the hue to a maximum of 360 as it's HSL's maximum of 360 degrees
            $h = (int) fmod($hash, 359.0); // TODO: Check if 359 is really of advantage here, instead of 360
            // The hue is already at least 1 degree off to every other, using a limited set of saturation values
            // further ensures that colors are distinct enough even if similar
            $s = [35, 50, 65][$h % 3];

            self::$entryColors[$text] = [$h, $s];
        } else {
            [$h, $s] = self::$entryColors[$text];
        }

        // We use a fixed luminosity to ensure good and equal contrast in both dark and light mode
        return sprintf('~"hsl(%d %d%% 50%% / %d%%)"', $h, $s, $transparency);
    }
}
