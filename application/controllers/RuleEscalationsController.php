<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Data\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\EscalationForm;
use Icinga\Module\Notifications\Repository\RuleEntryRepository;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class RuleEscalationsController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');
    }

    public function addAction(): void
    {
        $form = (new EscalationForm(new NotificationConfigProvider()))
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, EscalationForm $form) {
                $form->populate([
                    'rule_id' => $this->params->getRequired('rule'),
                    'position' => $this->params->getRequired('position')
                ]);
            })->on(Form::ON_SUBMIT, function (EscalationForm $form) {
                $escalation = $form->getEscalation();

                Database::get()->transaction(
                    fn(Connection $db) => (new RuleEntryRepository($db))->create($escalation)
                );

                Notification::success($this->translate('Created escalation'));
                $this->closeModalAndRefreshRemainingViews(Links::eventRule($escalation->ruleId));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Create Escalation'));

        $this->getDocument()->addHtml($form);
    }
}
