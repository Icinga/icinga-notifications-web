<?php

/* Icinga Web 2 | (c) 2018 Icinga Development Team | GPLv2+ */

/** @var $this \Icinga\Application\Modules\Module */

$this->provideHook('authentication', 'SessionStorage', true);
$this->addRoute('static-file', new Zend_Controller_Router_Route_Regex(
    'notifications-(.[^.]*)(\..*)',
    [
        'controller' => 'daemon',
        'action' => 'script',
        'module' => 'notifications'
    ],
    [
        1 => 'file',
        2 => 'extension'
    ]
));
