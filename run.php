<?php

/* Icinga Web 2 | (c) 2018 Icinga Development Team | GPLv2+ */

/** @var $this \Icinga\Application\Modules\Module */

$this->provideHook('authentication', 'SessionStorage', true);
$this->addRoute('static-worker-file', new Zend_Controller_Router_Route_Static(
    'icinga-notifications-worker.js',
    [
        'controller' => 'daemon',
        'action' => 'script',
        'module' => 'notifications'
    ]
));
