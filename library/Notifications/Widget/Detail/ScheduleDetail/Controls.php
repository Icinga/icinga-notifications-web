<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail\ScheduleDetail;

use DateTime;
use DateTimeZone;
use Icinga\Application\Icinga;
use IntlTimeZone;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\FormUid;
use ipl\Web\Url;
use Throwable;

class Controls extends Form
{
    use Translation;
    use FormUid;

    /** @var string The default mode */
    public const DEFAULT_MODE = 'week';

    protected $method = 'POST';

    protected $defaultAttributes = ['class' => 'schedule-controls', 'name' => 'schedule-detail-controls-form'];

    /** @var ?string */
    protected ?string $defaultTimezone = null;

    /**
     * Set the default timezone
     *
     * @param string $defaultTimezone
     *
     * @return $this
     */
    public function setDefaultTimezone(string $defaultTimezone): static
    {
        $this->defaultTimezone = $defaultTimezone;

        return $this;
    }

    /**
     * Get the timezone the user wants to see the schedule in
     *
     * @return string
     */
    public function getTimezone(): string
    {
        $el = $this->getElement('timezone');
        if ($el->isValid()) {
            return $el->getValue();
        }

        return $this->defaultTimezone ?? date_default_timezone_get();
    }

    /**
     * Get the number of days the user wants to see
     *
     * @return int
     */
    public function getNumberOfDays(): int
    {
        return match ($this->getPopulatedValue('mode')) {
            'day' => 1,
            'weeks' => 14,
            'month' => 31,
            default => 7
        };
    }

    protected function assemble(): void
    {
        $this->addElement($this->createUidElement());
        $this->addElementLoader('ipl\\Web\\FormElement', 'Element');

        $this->addElement('suggestion', 'timezone', [
            'required' => true,
            'data-auto-submit' => true,
            'label' => $this->translate('Show in Timezone'),
            'placeholder' => $this->translate('Enter a timezone'),
            'suggestionsUrl' => Url::fromPath(
                'notifications/suggest/timezone',
                ['showCompact' => true, '_disableLayout' => 1, 'default' => $this->defaultTimezone]
            ),
            'decorators' => [
                'Label' => [
                    'name' => 'Label',
                    'options' => ['uniqueName' => fn(string $name) => Icinga::app()->getRequest()->protectId($name)]
                ]
            ],
            'validators' => [new CallbackValidator(function ($value, $validator) {
                if ($value === $this->defaultTimezone) {
                    return true;
                }

                // https://github.com/php/php-src/issues/11874#issuecomment-1666223477
                $timezones = IntlTimeZone::createEnumeration() ?: [];

                foreach ($timezones as $tz) {
                    if ($tz !== $value) {
                        continue;
                    }

                    try {
                        if ((new DateTime('now', new DateTimeZone($tz)))->getTimezone()->getLocation()) {
                            return true;
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }

                // TODO: Not exactly obvious or intuitive
                $this->getElement('timezone')
                    ->getAttributes()
                    ->set('pattern', sprintf(
                        '^\s*(?!%s\b).*\s*$',
                        $value
                    ));

                return false;
            })]
        ]);

        $param = 'mode';
        $options = [
            'day' => $this->translate('Day'),
            'week' => $this->translate('Week'),
            'weeks' => $this->translate('2 Weeks'),
            'month' => $this->translate('Month')
        ];

        $this->addElement('hidden', $param, ['required' => true]);

        $chosenMode = $this->getPopulatedValue('mode');
        $viewModeSwitcher = HtmlElement::create('fieldset', ['class' => 'view-mode-switcher']);
        foreach ($options as $value => $label) {
            $input = $this->createElement('input', $param, [
                'class' => 'autosubmit',
                'type'  => 'radio',
                'id' => $param . '-' . $value,
                'value' => $value,
                'checked' => $value === $chosenMode
            ]);

            $viewModeSwitcher->addHtml(
                $input,
                new HtmlElement('label', Attributes::create(['for' => $param . '-' . $value]), Text::create($label))
            );
        }

        $this->addHtml($viewModeSwitcher);
    }
}
