<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Exception;

use Exception;

class InvalidFilterParameterException extends Exception
{
    public function __construct(string $parameter)
    {
        parent::__construct(sprintf('Invalid request parameter: Filter column %s is not allowed', $parameter));
    }
}
