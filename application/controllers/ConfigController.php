<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Config;
use Icinga\Module\Notifications\Forms\DatabaseConfigForm;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tab;
use Icinga\Web\Widget\Tabs;
use ipl\Web\Compat\CompatController;

class ConfigController extends CompatController
{
    public function init()
    {
        $this->assertPermission('config/modules');

        parent::init();
    }

    public function databaseAction()
    {
        $moduleConfig = Config::module('notifications');
        $form = (new DatabaseConfigForm())
            ->populate($moduleConfig->getSection('database'))
            ->on(DatabaseConfigForm::ON_SUCCESS, function ($form) use ($moduleConfig) {
                $moduleConfig->setSection('database', $form->getValues());
                $moduleConfig->saveIni();

                Notification::success(t('New configuration has successfully been stored'));
            })->handleRequest($this->getServerRequest());

        $this->mergeTabs($this->Module()->getConfigTabs()->activate('database'));

        $this->addContent($form);
    }

    /**
     * Merge tabs with other tabs contained in this tab panel
     *
     * @param Tabs $tabs
     *
     * @return void
     */
    protected function mergeTabs(Tabs $tabs): void
    {
        /** @var Tab $tab */
        foreach ($tabs->getTabs() as $tab) {
            $this->tabs->add($tab->getName(), $tab);
        }
    }
}
