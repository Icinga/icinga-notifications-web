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

class NotificationRuleController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');
    }

    public function addAction(): void
    {
        $form = (new EscalationForm(
            new NotificationConfigProvider(),
            EscalationForm::NOTIFICATION_RULE,
            $this->translate('Add Recipients')
        ))
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, EscalationForm $form) {
                $form->populate([
                    'rule_id' => $this->params->getRequired('rule'),
                    'position' => 0
                ]);
            })->on(Form::ON_SUBMIT, function (EscalationForm $form) {
                $entry = $form->getEscalation();

                Database::get()->transaction(
                    fn(Connection $db) => (new RuleEntryRepository($db))->create($entry)
                );

                Notification::success($this->translate('Added notification recipients'));
                $this->closeModalAndRefreshRemainingViews(Links::eventRule($entry->ruleId));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Add Notification Recipients'));

        $this->getDocument()->addHtml($form);
    }

    public function editAction(): void
    {
        $form = (new EscalationForm(
            new NotificationConfigProvider(),
            EscalationForm::NOTIFICATION_RULE
        ))
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, EscalationForm $form) {
                $entry = (new RuleEntryRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($entry === null) {
                    $this->httpNotFound($this->translate('No notification recipients found.'));
                }

                $form->setEscalation($entry);
            })->on(Form::ON_SUBMIT, function (EscalationForm $form) {
                $entry = $form->getEscalation();

                if ($form->hasBeenDeleted()) {
                    Database::get()->transaction(
                        fn(Connection $db) => (new RuleEntryRepository($db))->delete($entry->id)
                    );

                    Notification::success($this->translate('Deleted notification recipients.'));
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new RuleEntryRepository($db))->update($entry)
                    );

                    Notification::success($this->translate('Updated notification recipients'));
                }

                $this->closeModalAndRefreshRemainingViews(Links::eventRule($entry->ruleId));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Notification Recipients'));

        $this->getDocument()->addHtml($form);
    }
}
