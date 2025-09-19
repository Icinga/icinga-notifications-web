<?php

namespace Icinga\Module\Notifications\Api\Elements;

use Icinga\Exception\Http\HttpException;
use InvalidArgumentException;
use ValueError;

enum HttpMethod: string
{
    case GET = 'get';
    case POST = 'post';
    case PUT = 'put';
    case DELETE = 'delete';

    public static function fromString(string $method): self
    {
        try {
            return self::from(strtolower($method));
        } catch (ValueError) {
             throw (new HttpException(405, "HTTP method $method is not supported"));
        }
    }

    /**
     * Returns the current enum case as string in uppercase.
     *
     * @return string
     */
    public function uppercase(): string
    {
        return $this->name;
    }

    /**
     * Returns the current enum case as string in lowercase.
     */
    public function lowercase(): string
    {
        return $this->value;
    }
}
