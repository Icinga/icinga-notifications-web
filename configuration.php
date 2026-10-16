<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Icinga\Application\Modules\Module;
use Icinga\Authentication\Auth;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;

/** @var Module $this */

$auth = Auth::getInstance();
$authenticated = $auth->getUser() !== null;
if (! $authenticated) {
    return;
}

$section = $this->menuSection(
    N_('Notifications'),
    [
        'icon' => 'bell-alt',
        'priority' => 52
    ]
);

if ($auth->hasPermission('notifications/view/incidents')) {
    $section->add(
        N_('Incidents'),
        [
            'icon'          => 'th-list',
            'description'   => $this->translate('List open and closed incidents.'),
            'url'           => Links::incidents(),
            'priority'      => 10
        ]
    );
}

if ($auth->hasPermission('notifications/view/history')) {
    $section->add(
        N_('History'),
        [
            'icon'          => 'paper-plane',
            'description'   => $this->translate('List sent and failed notifications.'),
            'url'           => Links::history(),
            'priority'      => 20
        ]
    );
}

if ($auth->hasPermission('notifications/config/event-rules')) {
    $section->add(
        N_('Event Rules'),
        [
            'icon'          => Icons::RULE_MATCHED,
            'description'   => $this->translate(
                'Define rules when to route events to whom. Escalation rules notify recipients in stages and'
                . ' escalate based on incident age or severity. Notification rules notify a fixed set of recipients'
                . ' as soon as an event matches the filter.'
            ),
            'url'           => Links::eventRules(),
            'priority'      => 30
        ]
    );
}

if ($auth->hasPermission('notifications/config/schedules')) {
    $section->add(
        N_('Schedules'),
        [
            'icon'          => Icons::SCHEDULE,
            'description'   => $this->translate(
                'Organize contacts and contact groups in time-based schedules and let them rotate automatically.'
                . ' You can define multiple rotations with different patterns to set priorities.'
                . ' Schedules can also be used as recipients for event rules.'
            ),
            'url'           => Links::schedules(),
            'priority'      => 40
        ]
    );
}

if ($auth->hasPermission('notifications/config/contacts')) {
    $section->add(
        N_('Contacts'),
        [
            'icon'          => Icons::USER,
            'description'   => $this->translate('Configure contacts and their channel specific addresses.'),
            'url'           => Links::contacts(),
            'priority'      => 50
        ]
    );

    $section->add(
        N_('Contact Groups'),
        [
            'icon'          => Icons::CONTACTGROUP,
            'description'   => $this->translate('Combine multiple contacts in groups.'),
            'url'           => Links::contactGroups(),
            'priority'      => 60
        ]
    );
}

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
    'notifications/view/contacts',
    $this->translate('Allow to view contacts')
);

$this->providePermission(
    'notifications/view/incidents',
    $this->translate('Allow to view incidents')
);

$this->providePermission(
    'notifications/view/history',
    $this->translate('Allow to view history of notifications')
);

$this->providePermission(
    'notifications/api',
    $this->translate('Allow to modify configuration via API')
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
$this->provideJsFile('quick-action.js');
