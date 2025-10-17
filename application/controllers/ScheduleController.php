<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\MoveRotationForm;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Module\Notifications\Forms\ScheduleForm;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Util\ScheduleDateTimeFactory;
use Icinga\Module\Notifications\Web\Control\TimezonePicker;
use Icinga\Module\Notifications\Widget\Detail\ScheduleDetail;
use Icinga\Module\Notifications\Widget\RecipientSuggestions;
use Icinga\Module\Notifications\Widget\TimezoneWarning;
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
            ->filter(Filter::equal('schedule.id', $id));

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
            ))->openInModal()
        );

        $this->controls->addAttributes(['class' => 'schedule-detail-controls']);

        $scheduleControls = (new ScheduleDetail\Controls())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->populate(['mode' => $this->params->get('mode')])
            ->on(Form::ON_SUCCESS, function (ScheduleDetail\Controls $controls) use ($id) {
                $redirectUrl = Links::schedule($id)->with(['mode' => $controls->getMode()]);
                $requestUrl = Url::fromRequest();
                if ($requestUrl->getParam('mode') !== $controls->getValue('mode')) {
                    $defaultTimezoneParam = TimezonePicker::DEFAULT_TIMEZONE_PARAM;
                    if ($requestUrl->hasParam($defaultTimezoneParam)) {
                        $redirectUrl->addParams(
                            [$defaultTimezoneParam => $requestUrl->getParam($defaultTimezoneParam)]
                        );
                    }
                    $this->redirectNow($redirectUrl);
                }
            })
            ->handleRequest($this->getServerRequest());

        $timezonePicker = $this->createTimezonePicker($schedule->timezone);

        $this->addControl($timezonePicker);
        $this->addControl($scheduleControls);
        $this->addContent(new ScheduleDetail($schedule, $scheduleControls));
    }

    public function settingsAction(): void
    {
        $this->setTitle($this->translate('Edit Schedule'));
        $scheduleId = (int) $this->params->getRequired('id');

        $form = new ScheduleForm(Database::get());
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
        $form = (new ScheduleForm(Database::get()))
            ->setShowTimezoneDropdown()
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
        $displayTimezone = $this->params->get('display_timezone');
        $this->setTitle($this->translate('Add Rotation'));

        $scheduleTimezone = $this->getScheduleTimezone($scheduleId);

        if ($displayTimezone !== $scheduleTimezone) {
            $this->addContent(new TimezoneWarning($scheduleTimezone));
        }

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
            $this->addContent($form);
        }
    }

    public function editRotationAction(): void
    {
        $id = (int) $this->params->getRequired('id');
        $displayTimezone = $this->params->get('display_timezone');
        $scheduleId = (int) $this->params->getRequired('schedule');
        $this->setTitle($this->translate('Edit Rotation'));

        $scheduleTimezone = $this->getScheduleTimezone($scheduleId);

        if ($displayTimezone !== $scheduleTimezone) {
            $this->addContent(new TimezoneWarning($scheduleTimezone));
        }

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
     * Get the timezone of a schedule
     *
     * @param int $scheduleId The ID of the schedule
     *
     * @return string The timezone of the schedule
     */
    protected function getScheduleTimezone(int $scheduleId): string
    {
        return Schedule::on(Database::get())
            ->filter(Filter::equal('schedule.id', $scheduleId))
            ->first()
            ->timezone;
    }

    /**
     * Create a timezone picker control
     *
     * @param string $defaultTimezone The default timezone to use if none is set in the request
     *
     * @return TimezonePicker The timezone picker control
     */
    protected function createTimezonePicker(string $defaultTimezone): TimezonePicker
    {
        $defaultTimezoneParam = TimezonePicker::DEFAULT_TIMEZONE_PARAM;
        $timezoneParam = $this->params->shift($defaultTimezoneParam);

        ScheduleDateTimeFactory::setDisplayTimezone($timezoneParam ?? $defaultTimezone);

        return (new TimezonePicker())
            ->populate([$defaultTimezoneParam => $timezoneParam ?? $defaultTimezone])
            ->on(
                TimezonePicker::ON_SUBMIT,
                function (TimezonePicker $timezonePicker) use ($defaultTimezoneParam) {
                    $requestUrl = Url::fromRequest();
                    $pickedTimezone = $timezonePicker->getValue($defaultTimezoneParam);
                    if ($requestUrl->getParam($defaultTimezoneParam) !== $pickedTimezone) {
                        $this->redirectNow($requestUrl->with([$defaultTimezoneParam => $pickedTimezone]));
                    }
                }
            )->handleRequest($this->getServerRequest());
    }
}
