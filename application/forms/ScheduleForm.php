<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\Data\Schedule as ScheduleData;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Web\Session;
use IntlTimeZone;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use Throwable;

class ScheduleForm extends CompatForm
{
    use CsrfCounterMeasure;

    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    /**
     * Get whether the duplicate button was pressed
     *
     * @return bool
     */
    public function hasBeenDuplicated(): bool
    {
        return $this->getPressedSubmitElement()?->getName() === 'duplicate';
    }

    public function __construct()
    {
        $this->applyDefaultElementDecorators();
    }

    public function setSchedule(Schedule $schedule): static
    {
        $this->populate($this->prepareFormPopulate($schedule));

        return $this;
    }

    public function getSchedule(): ScheduleData
    {
        return new ScheduleData(
            $this->getValue('id'),
            $this->getValue('name'),
            $this->getValue('timezone')
        );
    }

    protected function assemble(): void
    {
        $this->addElement('hidden', 'id');
        $scheduleId = $this->getPopulatedValue('id') ?: null;
        if ($scheduleId !== null) {
            $scheduleId = (int) $scheduleId;
        }

        if ($scheduleId === null) {
            $this->addHtml(new HtmlElement(
                'p',
                new Attributes(['class' => 'description']),
                new Text($this->translate(
                    'Organize contacts and contact groups in time-based schedules and let them rotate'
                    . ' automatically. You can define multiple rotations with different patterns to set'
                    . ' priorities. Schedules can also be used as recipients for event rules.'
                ))
            ));
        }

        $this->addElement('text', 'name', [
            'required'      => true,
            'label'         => $this->translate('Schedule Name'),
            'placeholder'   => $this->translate('e.g. working hours, on call, etc ...'),
            'validators'    => [
                new CallbackValidator(function ($value, $validator) use ($scheduleId) {
                    $schedules = Schedule::on(Database::get())
                        ->columns('id')
                        ->filter(Filter::equal('name', $value));
                    if ($scheduleId !== null && ! $this->hasBeenDuplicated()) {
                        $schedules->filter(Filter::unequal('id', $scheduleId));
                    }

                    if ($schedules->first() !== null) {
                        $validator->addMessage($this->translate('A schedule with this name already exists'));

                        return false;
                    }

                    return true;
                })
            ]
        ]);

        $this->addElement(
            'suggestion',
            'timezone',
            [
                'required'       => true,
                'suggestionsUrl' => Url::fromPath('notifications/suggest/timezone', [
                    'showCompact'    => true,
                    '_disableLayout' => 1
                ]),
                'label'          => $this->translate('Schedule Timezone'),
                'value'          => date_default_timezone_get(),
                'validators'     => [
                    new CallbackValidator(function ($value, $validator) {
                        // https://github.com/php/php-src/issues/11874#issuecomment-1666223477
                        $timezones = IntlTimeZone::createEnumeration() ?: [];

                        foreach ($timezones as $tz) {
                            try {
                                if (
                                    (new DateTime('now', new DateTimeZone($tz)))->getTimezone()->getLocation()
                                    && $value === $tz
                                ) {
                                    return true;
                                }
                            } catch (Throwable) {
                                continue;
                            }
                        }

                        $validator->addMessage($this->translate('Invalid timezone'));

                        return false;
                    })
                ]
            ]
        );

        $this->addElement('submit', 'submit', [
            'label' => $scheduleId === null
                ? $this->translate('Create Schedule')
                : $this->translate('Save Changes')
        ]);

        if ($scheduleId !== null) {
            $removeBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);

            $duplicateBtn = $this->createElement('submit', 'duplicate', [
                'label' => $this->translate('Duplicate')
            ]);
            $this->registerElement($duplicateBtn);

            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent(
                $removeBtn,
                $duplicateBtn
            ));
        }

        $this->addCsrfCounterMeasure(Session::getSession()->getId());
    }

    /**
     * Fetch the values from the database
     *
     * @param Schedule $schedule
     *
     * @return array<string, string>
     */
    private function prepareFormPopulate(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'timezone' => $schedule->timezone
        ];
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || ($this->hasBeenSent() && $this->hasBeenDuplicated());
    }
}
