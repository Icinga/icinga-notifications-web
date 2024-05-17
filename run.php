<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

/** @var \Icinga\Application\Modules\Module $this */

$this->provideHook('Notifications/ObjectsRenderer');
$this->provideHook('authentication', 'SessionStorage', true);
