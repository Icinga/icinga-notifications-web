<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use DateTimeZone;
use Icinga\Module\Notifications\Form\Data\Schedule as ScheduleData;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Web\Session;
use IntlTimeZone;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use Throwable;

class ScheduleForm extends CompatForm
{
    use CsrfCounterMeasure;

    protected ?string $submitLabel = null;

    protected bool $showRemoveButton = false;

    protected bool $showTimezoneSuggestionInput = false;

    public function setSubmitLabel(string $label): static
    {
        $this->submitLabel = $label;

        return $this;
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? $this->translate('Create Schedule');
    }

    public function setShowRemoveButton(bool $state = true): static
    {
        $this->showRemoveButton = $state;

        return $this;
    }

    /**
     * Set whether to show the timezone dropdown or not
     *
     * @param bool $state If true, the timezone dropdown will be shown (defaults to true)
     *
     * @return $this
     */
    public function setShowTimezoneSuggestionInput(bool $state = true): static
    {
        $this->showTimezoneSuggestionInput = $state;

        return $this;
    }

    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
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
        $timezone = null;
        if ($this->showTimezoneSuggestionInput) {
            $timezone = $this->getValue('timezone');
        }

        return new ScheduleData(
            $this->getValue('id'),
            $this->getValue('name'),
            $timezone
        );
    }

    protected function assemble(): void
    {
        if (! $this->showRemoveButton) {
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

        $this->addElement('hidden', 'id');
        $scheduleId = $this->getPopulatedValue('id');
        if ($scheduleId !== null) {
            $scheduleId = (int) $scheduleId;
        }

        $this->addElement('text', 'name', [
            'required'      => true,
            'label'         => $this->translate('Schedule Name'),
            'placeholder'   => $this->translate('e.g. working hours, on call, etc ...')
        ]);

        if ($this->showTimezoneSuggestionInput) {
            $this->addElement(
                'suggestion',
                'timezone',
                [
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
        }

        $this->addElement('submit', 'submit', [
            'label' => $this->getSubmitLabel()
        ]);

        if ($this->showRemoveButton) {
            $removeBtn = $this->createElement('submit', 'delete', [
                'label' => $this->translate('Delete'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);

            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent($removeBtn));
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
        $values = [
            'id' => $schedule->id,
            'name' => $schedule->name
        ];
        if ($this->showTimezoneSuggestionInput) {
            $values['timezone'] = $schedule->timezone;
        }

        return $values;
    }
}
