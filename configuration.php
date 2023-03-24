<?php

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
        'url'           => 'noma/contacts'
    ]
);

$this->provideConfigTab(
    'database',
    [
        'title' => $this->translate('Database'),
        'label' => $this->translate('Database'),
        'url'   => 'config/database'
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
