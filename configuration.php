<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

/** @var \Icinga\Application\Modules\Module $this */

$this->provideRestriction(
    'noma/filter/objects',
    $this->translate('Restrict access to the objects that match the filter')
);
