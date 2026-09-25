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

class RuleEscalationController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');
    }

    public function editAction(): void
    {
        $form = (new EscalationForm(new NotificationConfigProvider()))
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, EscalationForm $form) {
                $escalation = (new RuleEntryRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($escalation === null) {
                    $this->httpNotFound($this->translate('Escalation not found.'));
                }

                $form->setEscalation($escalation);
            })->on(Form::ON_SUBMIT, function (EscalationForm $form) {
                $escalation = $form->getEscalation();

                if ($form->hasBeenDeleted()) {
                    Database::get()->transaction(
                        fn(Connection $db) => (new RuleEntryRepository($db))->delete($escalation->id)
                    );

                    Notification::success($this->translate('Deleted escalation'));
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new RuleEntryRepository($db))->update($escalation)
                    );

                    Notification::success($this->translate('Updated escalation'));
                }

                $this->closeModalAndRefreshRemainingViews(Links::eventRule($escalation->ruleId));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Escalation'));

        $this->getDocument()->addHtml($form);
    }
}
