<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\ChannelForm;
use Icinga\Module\Notifications\Repository\ChannelRepository;
use Icinga\Web\Notification;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;

class ChannelController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        (new ChannelForm(Database::get()))
            ->on(Form::ON_REQUEST, function ($_, ChannelForm $form) {
                $channel = (new ChannelRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($channel === null) {
                    $this->httpNotFound($this->translate('Channel not found'));
                }

                $form->setChannel($channel);

                $this->addTitleTab(sprintf($this->translate('Channel: %s'), $channel->name));

                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function (ChannelForm $form) {
                $channel = $form->getChannel();

                if ($form->getPressedSubmitElement()->getName() === 'delete') {
                    Database::get()->transaction(
                        fn(Connection $db) => (new ChannelRepository($db))->delete($channel->id)
                    );
                    Notification::success(sprintf(
                        $this->translate('Deleted channel "%s" successfully'),
                        $channel->name
                    ));
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new ChannelRepository($db))->update($channel)
                    );
                    Notification::success(sprintf(
                        $this->translate('Channel "%s" has successfully been saved'),
                        $channel->name
                    ));
                }

                $this->redirectNow('__CLOSE__');
            })->on(Form::ON_SENT, function (ChannelForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                if (! $this->getResponse()->isRedirect()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })->handleRequest($this->getServerRequest());
    }
}
