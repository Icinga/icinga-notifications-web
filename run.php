<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

$this->addRoute('api/v1/object-filter', new Zend_Controller_Router_Route_Static(
    'noma/api/v1/object-filter',
    [
        'controller'    => 'api-v1',
        'action'        => 'object-filter',
        'module'        => 'noma'
    ]
));
