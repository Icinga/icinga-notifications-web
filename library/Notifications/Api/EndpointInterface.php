<?php

namespace Icinga\Module\Notifications\Api;

interface EndpointInterface
{
    public function getEndpoint(): string;
    public function getAllowedMethods(): array;
}
