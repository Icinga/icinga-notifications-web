<?php

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Schedule;
use Icinga\Module\Noma\Widget\Calendar\Controls;
use ipl\Html\Form;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
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

        if ($scheduleId) {
            $form = new Form();
            $form->setMethod('GET');
            $form->addElement('select', 'schedule', [
                'options' => $schedules,
                'class' => 'autosubmit'
            ]);

            $form->handleRequest($this->getServerRequest());
            $this->addControl($form);
        }

        $this->addControl(
            new ButtonLink(null, Url::fromPath('noma/schedule/add'), 'plus', [
                'data-no-icinga-ajax' => true,
                'data-icinga-modal' => true
            ])
        );

        if ($scheduleId) {
            $this->addControl(
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

        $controls = (new Controls())
            ->setAction(Url::fromRequest()->getAbsoluteUrl());
        if ($this->getRequest()->getHeader('X-Icinga-Container') === 'modal-content') {
            $this->getResponse()->setHeader('X-Icinga-Modal-Layout', 'wide');
            $controls->setBaseTarget('modal-content');
        }

        $this->addContent(new \Icinga\Module\Noma\Widget\Schedule(
            $controls->handleRequest($this->getServerRequest()),
            $schedule
        ));

        $this->setTitle($this->translate('Schedules'));
        $this->getTabs()->activate('schedules');
    }

    public function getTabs()
    {
        return parent::getTabs()
            ->add('schedules', [
                'label' => $this->translate('Schedules'),
                'url'   => Url::fromRequest()
            ])->add('contacts', [
                'label' => $this->translate('Contacts'),
                'url'   => Url::fromPath('noma/contacts')
            ]);
    }
}