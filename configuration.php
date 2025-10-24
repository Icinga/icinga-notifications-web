<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

use Icinga\Application\Modules\Module;

/** @var Module $this */

$section = $this->menuSection(
    N_('Notifications'),
    [
        'icon' => 'bell-alt',
        'priority' => 52
    ]
);

$section->add(
    N_('Configuration'),
    [
        'icon'          => 'wrench',
        'description'   => $this->translate('Configuration'),
        'url'           => 'notifications/schedules'
    ]
);

$section->add(
    N_('Events'),
    [
        'icon'          => 'history',
        'description'   => $this->translate('Events'),
        'url'           => 'notifications/events'
    ]
);

$this->providePermission(
    'notifications/config/schedules',
    $this->translate('Allow to configure schedules')
);

$this->providePermission(
    'notifications/config/event-rules',
    $this->translate('Allow to configure event rules')
);

$this->providePermission(
    'notifications/config/contacts',
    $this->translate('Allow to configure contacts and contact groups')
);

$this->providePermission(
    'notifications/api',
    $this->translate('Allow to modify configuration via API')
);

$this->provideRestriction(
    'notifications/filter/objects',
    $this->translate('Restrict access to the objects that match the filter')
);

$this->provideConfigTab(
    'database',
    [
        'title' => $this->translate('Database'),
        'label' => $this->translate('Database'),
        'url'   => 'config/database'
    ]
);

$this->provideConfigTab(
    'channels',
    [
        'title' => $this->translate('Channels'),
        'label' => $this->translate('Channels'),
        'url'   => 'channels'
    ]
);

$this->provideConfigTab(
    'sources',
    [
        'title' => $this->translate('Sources'),
        'label' => $this->translate('Sources'),
        'url'   => 'sources'
    ]
);

$section->add(
    N_('Incidents'),
    [
        'icon'          => 'th-list',
        'description'   => $this->translate('Incidents'),
        'url'           => 'notifications/incidents'
    ]
);

$this->provideJsFile('schedule.js');

$cssDirectory = $this->getCssDir();
$cssFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $cssDirectory,
    RecursiveDirectoryIterator::CURRENT_AS_PATHNAME | RecursiveDirectoryIterator::SKIP_DOTS
));

foreach ($cssFiles as $path) {
    $this->provideCssFile(ltrim(substr($path, strlen($cssDirectory)), DIRECTORY_SEPARATOR));
}

$this->provideJsFile('notifications.js');
