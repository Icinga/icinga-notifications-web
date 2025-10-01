<?php

namespace Icinga\Module\Notifications\Api\Util;

class BinaryUuidConverter
{
    public static function fromDb(string $binary): string
    {
        $hex = bin2hex($binary);
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function toDb(string $uuid): string
    {
        return hex2bin(str_replace('-', '', $uuid));
    }
}
