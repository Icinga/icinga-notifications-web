<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use ipl\I18n\Translation;
use ipl\Web\Widget\Tabs;

trait ConfigurationTabs
{
    use Translation;

    abstract public function getRequest();

    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    abstract public function Auth();
    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    public function getTabs(): Tabs
    {
        $tabs = parent::getTabs();
        if ($this->getRequest()->getActionName() === 'index') {
            if ($this->Auth()->hasPermission('notifications/config/schedules')) {
                $tabs->add('schedules', [
                    'label'      => $this->translate('Schedules'),
                    'url'        => match ($this->getRequest()->getControllerName()) {
                        'schedules' => $this->getRequest()->getUrl(),
                        default     => Links::schedules()
                    },
                    'baseTarget' => '_main'
                ]);
            }

            if ($this->Auth()->hasPermission('notifications/config/event-rules')) {
                $tabs->add('event-rules', [
                    'label'      => $this->translate('Event Rules'),
                    'url'        => match ($this->getRequest()->getControllerName()) {
                        'event-rules' => $this->getRequest()->getUrl(),
                        default       => Links::eventRules()
                    },
                    'baseTarget' => '_main'
                ]);
            }

            if ($this->Auth()->hasPermission('notifications/config/contacts')) {
                $tabs->add('contacts', [
                    'label'      => $this->translate('Contacts'),
                    'url'        => match ($this->getRequest()->getControllerName()) {
                        'contacts'  => $this->getRequest()->getUrl(),
                        default     => Links::contacts()
                    },
                    'baseTarget' => '_main'
                ])->add('contact-groups', [
                    'label'      => $this->translate('Contact Groups'),
                    'url'        => match ($this->getRequest()->getControllerName()) {
                        'contact-groups' => $this->getRequest()->getUrl(),
                        default          => Links::contactGroups()
                    },
                    'baseTarget' => '_main'
                ]);
            }
        }

        return $tabs;
    }
}
