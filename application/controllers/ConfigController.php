<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Config;
use Icinga\Module\Notifications\Forms\DatabaseConfigForm;
use Icinga\Web\Form;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tab;
use Icinga\Web\Widget\Tabs;
use ipl\Html\HtmlString;
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
        $nomaConfig = Config::module('noma');
        $form = (new DatabaseConfigForm())
            ->populate($nomaConfig->getSection('database'))
            ->on(DatabaseConfigForm::ON_SUCCESS, function ($form) use ($nomaConfig) {
                $nomaConfig->setSection('database', $form->getValues());
                $nomaConfig->saveIni();

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
