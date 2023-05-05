<?php

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Forms\EventForm;
use Icinga\Module\Noma\Forms\ScheduleForm;
use Icinga\Module\Noma\Widget\RecipientSuggestions;
use ipl\Html\Html;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class ScheduleController extends CompatController
{
    public function indexAction()
    {
        $scheduleId = (int) $this->params->getRequired('id');

        $form = new ScheduleForm();
        $form->setShowRemoveButton();
        $form->loadSchedule($scheduleId);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->on(ScheduleForm::ON_SUCCESS, function ($form) use ($scheduleId) {
            $form->editSchedule($scheduleId);
            $this->redirectNow(Url::fromPath('noma/schedules', ['schedule' => $scheduleId]));
        });
        $form->on(ScheduleForm::ON_SENT, function ($form) use ($scheduleId) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            } elseif ($form->hasBeenRemoved()) {
                $form->removeSchedule($scheduleId);
                $this->redirectNow(Url::fromPath('noma/schedules'));
            }
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addAction()
    {
        $form = new ScheduleForm();
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->on(ScheduleForm::ON_SUCCESS, function ($form) {
            $scheduleId = $form->addSchedule();
            $this->redirectNow(Url::fromPath('noma/schedules', ['schedule' => $scheduleId]));
        });
        $form->on(ScheduleForm::ON_SENT, function ($form) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            }
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addEventAction()
    {
        $scheduleId = (int) $this->params->getRequired('schedule');
        $start = $this->params->get('start');

        $form = new EventForm();
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('noma/schedule/suggest-recipient'));
        $form->populate(['when' => ['start' => $start]]);
        $form->on(EventForm::ON_SUCCESS, function ($form) use ($scheduleId) {
            $form->addEvent($scheduleId);
            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow('__CLOSE__');
        });
        $form->on(EventForm::ON_SENT, function () use ($form) {
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
            $this->addPart(Html::tag('div', ['id' => $this->getRequest()->getHeader('X-Icinga-Container')], $form));
        }
    }

    public function editEventAction()
    {
        $eventId = (int) $this->params->getRequired('id');
        $scheduleId = (int) $this->params->getRequired('schedule');

        $form = new EventForm();
        $form->setShowRemoveButton();
        $form->loadEvent($scheduleId, $eventId);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('noma/schedule/suggest-recipient'));
        $form->on(EventForm::ON_SUCCESS, function () use ($form, $eventId, $scheduleId) {
            $form->editEvent($scheduleId, $eventId);
            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow('__CLOSE__');
        });
        $form->on(EventForm::ON_SENT, function ($form) use ($eventId, $scheduleId) {
            if ($form->hasBeenCancelled()) {
                $this->redirectNow('__CLOSE__');
            } elseif ($form->hasBeenRemoved()) {
                $form->removeEvent($scheduleId, $eventId);
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
            $this->addPart(Html::tag('div', ['id' => $this->getRequest()->getHeader('X-Icinga-Container')], $form));
        }
    }

    public function suggestRecipientAction()
    {
        $suggestions = new RecipientSuggestions();
        $suggestions->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($suggestions);
    }
}
