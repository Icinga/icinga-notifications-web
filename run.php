<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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

$this->addRoute('notifications/api-plural', new Zend_Controller_Router_Route(
    'notifications/api/:version/:endpoint',
    [
        'module'        => 'notifications',
        'controller'    => 'api',
        'action'        => 'index'
    ]
));
$this->addRoute('notifications/api-single', new Zend_Controller_Router_Route(
    'notifications/api/:version/:endpoint/:identifier',
    [
        'module'        => 'notifications',
        'controller'    => 'api',
        'action'        => 'index'
    ]
));
