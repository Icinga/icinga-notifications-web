<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api;

interface EndpointInterface
{
    public function getEndpoint(): string;
    public function getAllowedMethods(): array;
}
