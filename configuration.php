<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

/** @var \Icinga\Application\Modules\Module $this */

$section = $this->menuSection(
    'NoMa',
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
        'url'           => 'noma/schedules'
    ]
);

$section->add(
    N_('Events'),
    [
        'icon'          => 'history',
        'description'   => $this->translate('Events'),
        'url'           => 'noma/events'
    ]
);

$section->add(
    N_('Event Rules'),
    [
        'icon'          => 'history',
        'description'   => $this->translate('Event Rules'),
        'url'           => 'noma/event-rules'
    ]
);

$this->providePermission(
    'noma/config/event-rules',
    $this->translate('Allow to configure event rules')
);

$this->provideRestriction(
    'noma/filter/objects',
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

$section->add(
    N_('Incidents'),
    [
        'icon'          => 'th-list',
        'description'   => $this->translate('Incidents'),
        'url'           => 'noma/incidents'
    ]
);

$cssDirectory = $this->getCssDir();
$cssFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $cssDirectory,
    RecursiveDirectoryIterator::CURRENT_AS_PATHNAME | RecursiveDirectoryIterator::SKIP_DOTS
));

foreach ($cssFiles as $path) {
    $this->provideCssFile(ltrim(substr($path, strlen($cssDirectory)), DIRECTORY_SEPARATOR));
}

$this->provideConfigTab(
    'channels',
    [
        'title' => $this->translate('Channels'),
        'label' => $this->translate('Channels'),
        'url'   => 'channels'
    ]
);
