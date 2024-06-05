<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

/** @var \Icinga\Application\Modules\Module $this */

if ($this::exists('icingadb')) {
    $this->provideHook('Notifications/ObjectsRenderer');
}

$this->provideHook('authentication', 'SessionStorage', true);
$this->addRoute(
    'static-file',
    new Zend_Controller_Router_Route_Regex(
        'notifications-(.[^.]*)(\..*)',
        [
            'controller' => 'daemon',
            'action'     => 'script',
            'module'     => 'notifications'
        ],
        [
            1 => 'file',
            2 => 'extension'
        ]
    )
);

$this->addRoute('notifications/api-v1-contacts', new Zend_Controller_Router_Route_Regex(
    'notifications/api/v1/contacts(?:\/(.+)|\?(.+))?',
    [
        'controller'    => 'api-v1-contacts',
        'action'        => 'index',
        'module'        => 'notifications',
        'identifier'    => null
    ],
    [
        1 => 'identifier'
    ]
));

$this->addRoute('notifications/api-v1-contactgroups', new Zend_Controller_Router_Route_Regex(
    'notifications/api/v1/contactgroups(?:\/(.+)|\?(.+))?',
    [
        'controller'    => 'api-v1-contactgroups',
        'action'        => 'index',
        'module'        => 'notifications',
        'identifier'    => null
    ],
    [
        1 => 'identifier'
    ]
));
