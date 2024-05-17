<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

/** @var \Icinga\Application\Modules\Module $this */

$this->provideHook('Notifications/ObjectsRenderer');
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
