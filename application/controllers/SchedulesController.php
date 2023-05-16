<?php

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\Calendar\Controls;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class SchedulesController extends CompatController
{
    public function indexAction()
    {
        $db = Database::get();
        $schedules = $db->fetchPairs(Schedule::on($db)->columns(['id', 'name'])->assembleSelect());

        $schedule = null;
        $scheduleId = $this->params->get('schedule', key($schedules));

        $controls = Html::tag('div', ['class' => 'schedule-control']);
        if ($scheduleId) {
            $form = new CompatForm();
            $form->setMethod('GET');
            $form->addAttributes(['class' => ['inline', 'select-schedule-control']]);
            $form->addElement('select', 'schedule', [
                'options' => $schedules,
                'class' => 'autosubmit',
                'label' => t('Select Schedule')
            ]);

            $form->handleRequest($this->getServerRequest());
            $controls->addHtml($form);
        }

        if ($scheduleId) {
            $controls->addHtml(
                new ButtonLink(null, Url::fromPath('noma/schedule', ['id' => $scheduleId]), 'cog', [
                    'data-no-icinga-ajax' => true,
                    'data-icinga-modal' => true
                ])
            );

            /** @var Schedule $schedule */
            $schedule = Schedule::on(Database::get())
                ->filter(Filter::equal('id', $scheduleId))
                ->first();
            if ($schedule === null) {
                $this->httpNotFound('Schedule not found');
            }
        }

        $controls->addHtml(
            new ButtonLink(
                'New Schedule',
                Url::fromPath('noma/schedule/add'),
                'plus',
                [
                    'class' => 'add-schedule-control',
                    'data-no-icinga-ajax' => true,
                    'data-icinga-modal' => true
                ]
            )
        );

        $calendarControls = (new Controls())
            ->setAction(Url::fromRequest()->getAbsoluteUrl());
        if ($this->getRequest()->getHeader('X-Icinga-Container') === 'modal-content') {
            $this->getResponse()->setHeader('X-Icinga-Modal-Layout', 'wide');
            $calendarControls->setBaseTarget('modal-content');
        }

        $this->addControl($controls);
        $this->controls->addAttributes(['class' => 'schedule-controls']);

        $this->addContent(new \Icinga\Module\Notifications\Widget\Schedule(
            $calendarControls->handleRequest($this->getServerRequest()),
            $schedule
        ));

        $this->setTitle($this->translate('Schedules'));
        $this->getTabs()->activate('schedules');
    }

    public function getTabs()
    {
        return parent::getTabs()
            ->add('schedules', [
                'label'         => $this->translate('Schedules'),
                'url'           => Url::fromPath('noma/schedules'),
                'baseTarget'    => '_main'
            ])->add('event-rules', [
                'label' => $this->translate('Event Rules'),
                'url'   => Url::fromPath('noma/event-rules')
            ])->add('contacts', [
                'label' => $this->translate('Contacts'),
                'url'   => Url::fromPath('noma/contacts')
            ]);
    }
}
