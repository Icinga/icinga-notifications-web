<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\ChannelForm;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class ChannelController extends CompatController
{
    /** @var Connection */
    private $db;

    public function init()
    {
        $this->assertPermission('config/modules');

        $this->db = Database::get();
    }

    public function indexAction()
    {
        $channelId = $this->params->getRequired('id');
        $query = Channel::on($this->db)
            ->filter(Filter::all(
                Filter::equal('id', $channelId),
                Filter::equal('deleted', 'n')
            ));

        /** @var Channel $channel */
        $channel = $query->first();
        if ($channel === null) {
            $this->httpNotFound(t('Channel not found'));
        }

        $this->addTitleTab(sprintf(t('Channel: %s'), $channel->name));

        $form = (new ChannelForm($this->db, $channelId))
            ->populate($channel)
            ->on(ChannelForm::ON_SUCCESS, function (ChannelForm $form) {
                if ($form->getPressedSubmitElement()->getName() === 'delete') {
                    Notification::success(sprintf(
                        t('Deleted channel "%s" successfully'),
                        $form->getValue('name')
                    ));
                } else {
                    Notification::success(sprintf(
                        t('Channel "%s" has successfully been saved'),
                        $form->getValue('name')
                    ));
                }

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }
}
