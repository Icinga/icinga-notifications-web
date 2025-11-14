<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\ChannelForm;
use Icinga\Web\Notification;
use ipl\Html\Contract\Form;
use ipl\Web\Compat\CompatController;

class ChannelController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $channelId = $this->params->getRequired('id');
        $form = (new ChannelForm(Database::get()))
            ->loadChannel($channelId)
            ->on(Form::ON_SUBMIT, function (ChannelForm $form) {
                if ($form->getPressedSubmitElement()->getName() === 'delete') {
                    $form->removeChannel();
                    Notification::success(sprintf(
                        t('Deleted channel "%s" successfully'),
                        $form->getValue('name')
                    ));
                } else {
                    $form->editChannel();
                    Notification::success(sprintf(
                        t('Channel "%s" has successfully been saved'),
                        $form->getValue('name')
                    ));
                }

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf(t('Channel: %s'), $form->getChannelName()));

        $this->addContent($form);
    }
}
