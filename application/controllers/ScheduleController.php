<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\MoveRotationForm;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Module\Notifications\Forms\ScheduleForm;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\RecipientSuggestions;
use Icinga\Module\Notifications\Widget\Schedule as ScheduleWidget;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class ScheduleController extends CompatController
{
    public function indexAction(): void
    {
        $id = (int) $this->params->getRequired('id');

        $query = Schedule::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('schedule.id', $id),
                Filter::equal('schedule.deleted', 'n')
            ));

        /** @var ?Schedule $schedule */
        $schedule = $query->first();
        if ($schedule === null) {
            $this->httpNotFound(t('Schedule not found'));
        }

        $this->addTitleTab(sprintf(t('Schedule: %s'), $schedule->name));

        $this->controls->addHtml(
            Html::tag('h2', null, $schedule->name),
            (new ButtonLink(
                null,
                Links::scheduleSettings($id),
                'cog'
            ))->openInModal(),
            (new ButtonLink(
                $this->translate('Add Rotation'),
                Links::rotationAdd($id),
                'plus'
            ))->openInModal()
        );

        $this->controls->addAttributes(['class' => 'schedule-detail-controls']);

        $scheduleControls = (new ScheduleWidget\Controls())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->populate(['mode' => $this->params->get('mode')])
            ->on(Form::ON_SUCCESS, function (ScheduleWidget\Controls $controls) use ($id) {
                $this->redirectNow(Links::schedule($id)->with(['mode' => $controls->getMode()]));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent(new ScheduleWidget($schedule, $scheduleControls));
    }

    public function settingsAction(): void
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

            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow(Links::schedule($scheduleId));
        });
        $form->on(ScheduleForm::ON_SENT, function ($form) use ($scheduleId) {
            if ($form->hasBeenRemoved()) {
                $form->removeSchedule($scheduleId);

                $this->redirectNow('__CLOSE__');
            }
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addAction(): void
    {
        $this->setTitle($this->translate('New Schedule'));
        $form = (new ScheduleForm())
            ->setAction($this->getRequest()->getUrl()->getAbsoluteUrl())
            ->on(Form::ON_SUCCESS, function (ScheduleForm $form) {
                $scheduleId = $form->addSchedule();

                $this->sendExtraUpdates(['#col1']);
                $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                $this->redirectNow(Links::schedule($scheduleId));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function addRotationAction(): void
    {
        $scheduleId = (int) $this->params->getRequired('schedule');
        $this->assertScheduleExists($scheduleId);

        $form = new RotationConfigForm($scheduleId, Database::get());
        $form->setAction($this->getRequest()->getUrl()->setParam('showCompact')->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->on(RotationConfigForm::ON_SENT, function ($form) {
            if (! $form->hasBeenSubmitted()) {
                foreach ($form->getPartUpdates() as $update) {
                    if (! is_array($update)) {
                        $update = [$update];
                    }

                    $this->addPart(...$update);
                }
            }
        });
        $form->on(RotationConfigForm::ON_SUCCESS, function (RotationConfigForm $form) use ($scheduleId) {
            $form->addRotation();
            $this->sendExtraUpdates(['#col1']);
            $this->closeModalAndRefreshRelatedView(Links::schedule($scheduleId));
        });

        $form->handleRequest($this->getServerRequest());

        if (empty($this->parts)) {
            $this->setTitle($this->translate('Add Rotation'));
            $this->addContent($form);
        }
    }

    public function editRotationAction(): void
    {
        $id = (int) $this->params->getRequired('id');
        $scheduleId = (int) $this->params->getRequired('schedule');
        $this->assertScheduleExists($scheduleId);

        $form = new RotationConfigForm($scheduleId, Database::get());
        $form->disableModeSelection();
        $form->setShowRemoveButton();
        $form->loadRotation($id);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->setParam('showCompact')->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->on(RotationConfigForm::ON_SUCCESS, function (RotationConfigForm $form) use ($id, $scheduleId) {
            $form->editRotation($id);
            $this->sendExtraUpdates(['#col1']);
            $this->closeModalAndRefreshRelatedView(Links::schedule($scheduleId));
        });
        $form->on(RotationConfigForm::ON_SENT, function (RotationConfigForm $form) use ($id, $scheduleId) {
            if ($form->hasBeenRemoved()) {
                $form->removeRotation($id);
                $this->sendExtraUpdates(['#col1']);
                $this->closeModalAndRefreshRelatedView(Links::schedule($scheduleId));
            } elseif ($form->hasBeenWiped()) {
                $form->wipeRotation();
                $this->sendExtraUpdates(['#col1']);
                $this->closeModalAndRefreshRelatedView(Links::schedule($scheduleId));
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
            $this->setTitle($this->translate('Edit Rotation'));
            $this->addContent($form);
        }
    }

    public function moveRotationAction(): void
    {
        $this->assertHttpMethod('POST');

        $form = new MoveRotationForm(Database::get());
        $form->on(MoveRotationForm::ON_SUCCESS, function (MoveRotationForm $form) {
            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow(Links::schedule($form->getScheduleId()));
        });

        $form->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    public function suggestRecipientAction(): void
    {
        $suggestions = new RecipientSuggestions();
        $suggestions->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($suggestions);
    }

    /**
     * Assert that a schedule with the given ID exists
     *
     * @param int $id
     *
     * @return void
     *
     * @throws HttpNotFoundException If the schedule with the given ID does not exist
     */
    private function assertScheduleExists(int $id): void
    {
        $query = Schedule::on(Database::get())
            ->columns('1')
            ->filter(Filter::all(
                Filter::equal('schedule.id', $id),
                Filter::equal('schedule.deleted', 'n')
            ));

        /** @var ?Schedule $schedule */
        $schedule = $query->first();
        if ($schedule === null) {
            $this->httpNotFound(t('Schedule not found'));
        }
    }
}
