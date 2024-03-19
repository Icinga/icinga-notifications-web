<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Forms\EntryForm;
use Icinga\Module\Notifications\Forms\ScheduleForm;
use Icinga\Module\Notifications\Widget\RecipientSuggestions;
use ipl\Html\Html;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class ScheduleController extends CompatController
{
    public function indexAction()
    {
        $this->setTitle($this->translate('Edit Schedule'));
        $scheduleId = (int) $this->params->getRequired('id');

        $form = new ScheduleForm();
        $form->setShowRemoveButton();
        $form->loadSchedule($scheduleId);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->on(ScheduleForm::ON_SUCCESS, function ($form) use ($scheduleId) {
            $form->editSchedule($scheduleId);
            $this->redirectNow(Url::fromPath('notifications/schedules', ['schedule' => $scheduleId]));
        });
        $form->on(ScheduleForm::ON_SENT, function ($form) use ($scheduleId) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            } elseif ($form->hasBeenRemoved()) {
                $form->removeSchedule($scheduleId);
                $this->redirectNow(Url::fromPath('notifications/schedules'));
            }
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addAction()
    {
        $this->setTitle($this->translate('New Schedule'));
        $form = new ScheduleForm();
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->on(ScheduleForm::ON_SUCCESS, function ($form) {
            $scheduleId = $form->addSchedule();
            $this->redirectNow(Url::fromPath('notifications/schedules', ['schedule' => $scheduleId]));
        });
        $form->on(ScheduleForm::ON_SENT, function ($form) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            }
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addEntryAction()
    {
        $scheduleId = (int) $this->params->getRequired('schedule');
        $start = $this->params->get('start');

        $form = new EntryForm();
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->populate(['when' => ['start' => $start]]);
        $form->on(EntryForm::ON_SUCCESS, function ($form) use ($scheduleId) {
            $form->addEntry($scheduleId);
            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow('__CLOSE__');
        });
        $form->on(EntryForm::ON_SENT, function () use ($form) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            } elseif (! $form->hasBeenSubmitted()) {
                foreach ($form->getPartUpdates() as $update) {
                    if (! is_array($update)) {
                        $update = [$update];
                    }

                    $this->addPart(...$update);
                }
            }
        });

        $form->handleRequest($this->getServerRequest());

        if (empty($this->parts)) {
            $this->addPart(Html::tag(
                'div',
                ['id' => $this->getRequest()->getHeader('X-Icinga-Container')],
                [
                    Html::tag('h2', null, $this->translate('Add Entry')),
                    $form
                ]
            ));
        }
    }

    public function editEntryAction()
    {
        $entryId = (int) $this->params->getRequired('id');
        $scheduleId = (int) $this->params->getRequired('schedule');

        $form = new EntryForm();
        $form->setShowRemoveButton();
        $form->loadEntry($scheduleId, $entryId);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->on(EntryForm::ON_SUCCESS, function () use ($form, $entryId, $scheduleId) {
            $form->editEntry($scheduleId, $entryId);
            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow('__CLOSE__');
        });
        $form->on(EntryForm::ON_SENT, function ($form) use ($entryId, $scheduleId) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            } elseif ($form->hasBeenRemoved()) {
                $form->removeEntry($scheduleId, $entryId);
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow('__CLOSE__');
            } elseif (! $form->hasBeenSubmitted()) {
                foreach ($form->getPartUpdates() as $update) {
                    if (! is_array($update)) {
                        $update = [$update];
                    }

                    $this->addPart(...$update);
                }
            }
        });

        $form->handleRequest($this->getServerRequest());

        if (empty($this->parts)) {
            $this->addPart(Html::tag(
                'div',
                ['id' => $this->getRequest()->getHeader('X-Icinga-Container')],
                [
                    Html::tag('h2', null, $this->translate('Edit Entry')),
                    $form
                ]
            ));
        }
    }

    public function suggestRecipientAction()
    {
        $suggestions = new RecipientSuggestions();
        $suggestions->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($suggestions);
    }
}
