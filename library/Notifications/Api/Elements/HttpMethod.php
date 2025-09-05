<?php

namespace Icinga\Module\Notifications\Api\Elements;

enum HttpMethod: string
{
    case get = 'GET';
    case post = 'POST';
    case put = 'PUT';
    case delete = 'DELETE';
}
