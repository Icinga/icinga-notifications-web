<?php

namespace Icinga\Module\Notifications\Api\Elements;

enum HttpMethod: string
{
    case GET = 'get';
    case POST = 'post';
    case PUT = 'put';
    case DELETE = 'delete';


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
