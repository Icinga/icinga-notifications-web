<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use DateTime;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\MoveRotationForm;
use Icinga\Module\Notifications\Forms\RotationConfigForm;
use Icinga\Module\Notifications\Forms\ScheduleForm;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\Detail\ScheduleDetail;
use Icinga\Module\Notifications\Widget\RecipientSuggestions;
use Icinga\Module\Notifications\Widget\TimezoneWarning;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class ScheduleController extends CompatController
{
    /** @var ?Session\SessionNamespace */
    private ?Session\SessionNamespace $session = null;

    public function init(): void
    {
        parent::init();

        $this->session = Session::getSession()->getNamespace('notifications.schedule');
    }

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
            Html::tag('strong', null, $schedule->name),
            (new ButtonLink(
                null,
                Links::scheduleSettings($id),
                'cog'
            ))->openInModal()
        );

        $this->controls->addAttributes(['class' => 'schedule-detail-controls']);

        $scheduleControls = (new ScheduleDetail\Controls())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->setDefaultTimezone($schedule->timezone)
            ->populate([
                'mode' => $this->params->get(
                    'mode',
                    $this->session->get(
                        'timeline.mode',
                        ScheduleDetail\Controls::DEFAULT_MODE
                    )
                ),
                'timezone' => $this->params->get(
                    'display_timezone',
                    $this->getDisplayTimezoneFromSession($schedule->timezone)
                )
            ])
            ->on(Form::ON_SUBMIT, function (ScheduleDetail\Controls $controls) use ($schedule) {
                $mode = $controls->getValue('mode');
                $timezone = $controls->getValue('timezone');

                $this->session->set('timeline.mode', $mode);

                if ($timezone === $schedule->timezone) {
                    $this->session->delete('schedule.display_timezone');
                } else {
                    $this->session->set('schedule.display_timezone', $timezone);
                }

                $redirectUrl = Links::schedule($schedule->id)->setParam('mode', $mode);
                if ($timezone) {
                    $redirectUrl->setParam('display_timezone', $timezone);
                }

                $this->redirectNow($redirectUrl);
            })
            ->handleRequest($this->getServerRequest());

        $this->addControl($scheduleControls);
        $this->addContent(new ScheduleDetail(
            $schedule,
            $scheduleControls,
            new DateTime('today', new DateTimeZone($scheduleControls->getTimezone())),
        ));
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
        $form->on(Form::ON_SUBMIT, function ($form) use ($scheduleId) {
            $form->editSchedule($scheduleId);

            $this->sendExtraUpdates(['#col1']);
            $this->redirectNow(Links::schedule($scheduleId));
        });
        $form->on(Form::ON_SENT, function ($form) use ($scheduleId) {
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
            ->setShowTimezoneSuggestionInput()
            ->setAction($this->getRequest()->getUrl()->setParam('showCompact')->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function (ScheduleForm $form) {
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
        $scheduleTimezone = $this->getScheduleTimezone($scheduleId);
        $displayTimezone = $this->getDisplayTimezoneFromSession($scheduleTimezone);
        $this->setTitle($this->translate('Add Rotation'));

        if ($displayTimezone !== $scheduleTimezone) {
            $this->addContent(new TimezoneWarning($scheduleTimezone));
        }

        $form = new RotationConfigForm($scheduleId, Database::get(), $displayTimezone, $scheduleTimezone);
        $form->setAction($this->getRequest()->getUrl()->setParam('showCompact')->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->on(Form::ON_SENT, function ($form) {
            if (! $form->hasBeenSubmitted()) {
                foreach ($form->getPartUpdates() as $update) {
                    if (! is_array($update)) {
                        $update = [$update];
                    }

                    $this->addPart(...$update);
                }
            }
        });
        $form->on(Form::ON_SUBMIT, function (RotationConfigForm $form) use ($scheduleId) {
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
        $scheduleId = (int) $this->params->getRequired('schedule');
        $scheduleTimezone = $this->getScheduleTimezone($scheduleId);
        $displayTimezone = $this->getDisplayTimezoneFromSession($scheduleTimezone);
        $this->setTitle($this->translate('Edit Rotation'));

        if ($displayTimezone !== $scheduleTimezone) {
            $this->addContent(new TimezoneWarning($scheduleTimezone));
        }

        $form = new RotationConfigForm($scheduleId, Database::get(), $displayTimezone, $scheduleTimezone);
        $form->disableModeSelection();
        $form->setShowRemoveButton();
        $form->loadRotation($id);
        $form->setSubmitLabel($this->translate('Save Changes'));
        $form->setAction($this->getRequest()->getUrl()->setParam('showCompact')->getAbsoluteUrl());
        $form->setSuggestionUrl(Url::fromPath('notifications/schedule/suggest-recipient'));
        $form->on(Form::ON_SUBMIT, function (RotationConfigForm $form) use ($id, $scheduleId) {
            $form->editRotation($id);
            $this->sendExtraUpdates(['#col1']);
            $this->closeModalAndRefreshRelatedView(Links::schedule($scheduleId));
        });
        $form->on(Form::ON_SENT, function (RotationConfigForm $form) use ($id, $scheduleId) {
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
        $form->on(Form::ON_SUBMIT, function (MoveRotationForm $form) {
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
     * Get the display timezone from the session
     *
     * @param string $defaultTimezone
     *
     * @return string
     */
    protected function getDisplayTimezoneFromSession(string $defaultTimezone): string
    {
        return $this->session->get('schedule.display_timezone', $defaultTimezone);
    }
}
