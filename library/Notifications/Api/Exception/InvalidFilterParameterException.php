<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Api\Exception;

use Exception;

class InvalidFilterParameterException extends Exception
{
    public function __construct(string $parameter)
    {
        parent::__construct(sprintf('Invalid request parameter: Filter column %s is not allowed', $parameter));
    }
}
