<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Psr\Http\Message\ServerRequestInterface;

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
     *
     * @return string
     */
    public function lowercase(): string
    {
        return $this->value;
    }

    /**
     * Retrieves an enum instance from a ServerRequestInterface by extracting the HTTP method.
     *
     * @param ServerRequestInterface $request The server request containing the HTTP method.
     *
     * @return HttpMethod The enum instance corresponding to the provided method.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        return self::from(strtolower($request->getMethod()));
    }
}
