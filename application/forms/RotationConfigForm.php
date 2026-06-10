<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use ArrayIterator;
use DateInterval;
use DateTime;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RotationMember;
use Icinga\Util\Json;
use Icinga\Web\Session;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\DeferredText;
use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SelectElement;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\GreaterThanValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormDecorator\IcingaFormDecorator;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Url;
use Locale;
use LogicException;

class RotationConfigForm extends CompatForm
{
    use CsrfCounterMeasure;

    /**
     * Whether experimental overrides are enabled
     *
     * @var bool
     * @internal Ignore this, seriously!
     */
    public const EXPERIMENTAL_OVERRIDES = false;

    /** @var Rotation */
    protected Rotation $rotation;

    /** @var int The ID of the affected schedule */
    protected int $scheduleId;

    /** @var ?string The label shown on the submit button */
    protected ?string $submitLabel = null;

    /** @var bool Whether to render the remove button */
    protected bool $showRemoveButton = false;

    /** @var ?Url The URL to fetch member suggestions from */
    protected ?Url $suggestionUrl = null;

    /** @var bool Whether the mode selection is disabled */
    protected bool $disableModeSelection = false;

    /** @var string The timezone to display the timeline in */
    protected string $displayTimezone;

    /** @var string The timezone the schedule is created in */
    protected string $scheduleTimezone;

    /**
     * Set the rotation to populate the form with
     *
     * @param Rotation $rotation
     *
     * @return $this
     */
    public function setRotation(Rotation $rotation): static
    {
        if ($rotation->schedule_id !== $this->scheduleId) {
            throw new LogicException('Refusing to load a rotation that does not belong to the schedule.');
        }

        $this->rotation = $rotation;
        $this->populate($this->rotationToFormData());

        return $this;
    }

    /**
     * Get the rotation as it's currently configured
     *
     * @return Rotation
     */
    public function getRotation(): Rotation
    {
        return $this->rotation;
    }

    /**
     * Set the label for the submit button
     *
     * @param string $label
     *
     * @return $this
     */
    public function setSubmitLabel(string $label): static
    {
        $this->submitLabel = $label;

        return $this;
    }

    /**
     * Get the label for the submit button
     *
     * @return string
     */
    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? $this->translate('Add Rotation');
    }

    /**
     * Set whether to render the remove button
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setShowRemoveButton(bool $state = true): static
    {
        $this->showRemoveButton = $state;

        return $this;
    }

    /**
     * Set the URL to fetch member suggestions from
     *
     * @param Url $url
     *
     * @return $this
     */
    public function setSuggestionUrl(Url $url): static
    {
        $this->suggestionUrl = $url;

        return $this;
    }

    /**
     * Disable the mode selection
     *
     * @return $this
     */
    public function disableModeSelection(): static
    {
        $this->disableModeSelection = true;

        return $this;
    }

    /**
     * Get multipart updates provided by this form's elements
     *
     * @return array
     */
    public function getPartUpdates(): array
    {
        $this->ensureAssembled();

        return $this->getElement('members')->prepareMultipartUpdate($this->getRequest());
    }

    /**
     * Get whether the remove button was pressed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'remove';
    }

    /**
     * Get whether the remove_all button was pressed
     *
     * @return bool
     */
    public function hasBeenWiped(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'remove_all';
    }

    /**
     * Create a new RotationConfigForm
     *
     * @param int $scheduleId
     * @param string $displayTimezone
     * @param string $scheduleTimezone
     */
    public function __construct(int $scheduleId, string $displayTimezone, string $scheduleTimezone)
    {
        $this->scheduleId = $scheduleId;
        $this->displayTimezone = $displayTimezone;
        $this->scheduleTimezone = $scheduleTimezone;
        $this->rotation = new Rotation();

        $this->applyDefaultElementDecorators();
    }

    protected function onSuccess()
    {
        $this->applyChanges();
    }

    protected function assembleModeSelection(): string
    {
        $value = $this->getPopulatedValue('mode');

        $modes = [
            'partial' => $this->translate('Partial Day'),
            'multi' => $this->translate('Multi Day'),
            '24-7' => $this->translate('24/7')
        ];

        $modeList = new HtmlElement('ul', Attributes::create([
            'class' => ['rotation-mode', $this->disableModeSelection ? 'disabled' : '']
        ]));
        foreach ($modes as $mode => $label) {
            $radio = $this->createElement('input', 'mode', [
                'type' => 'radio',
                'value' => $mode,
                'disabled' => $this->disableModeSelection,
                'id' => 'rotation-mode-' . $mode,
                'class' => 'autosubmit'
            ]);
            if ($value === null || $mode === $value) {
                $radio->getAttributes()->set('checked', true);
                $this->registerElement($radio);
                $value = $mode;
            }

            switch ($mode) {
                case 'partial':
                    $labelDescription = [
                        new HtmlElement(
                            'span',
                            null,
                            Text::create($this->translate('Daily shifts with a daily handoff at a defined time.'))
                        ),
                        new HtmlElement(
                            'span',
                            new Attributes(['class' => 'example']),
                            Text::create($this->translate('e.g. Working hours (Mon - Fri, 9AM - 5PM)'))
                        )
                    ];

                    break;
                case 'multi':
                    $labelDescription = [
                        new HtmlElement(
                            'span',
                            null,
                            Text::create($this->translate(
                                'Shifts start at a certain time on one day of the week and end on another.'
                            ))
                        ),
                        new HtmlElement(
                            'span',
                            new Attributes(['class' => 'example']),
                            Text::create($this->translate('e.g. Weekend shifts (Fri 5PM - Mon 9AM)'))
                        )
                    ];

                    break;
                case '24-7':
                    $labelDescription = [
                        new HtmlElement(
                            'span',
                            null,
                            Text::create($this->translate(
                                'Shifts start at a certain time of a day and last until the same time'
                                . ' on the next or any later day.'
                            ))
                        ),
                        new HtmlElement(
                            'span',
                            new Attributes(['class' => 'example']),
                            Text::create($this->translate('e.g. On-Call (24/7)'))
                        )
                    ];
            }

            $modeList->addHtml(new HtmlElement(
                'li',
                null,
                new HtmlElement(
                    'label',
                    null,
                    $radio,
                    new HtmlElement('div', Attributes::create(['class' => ['mode-img', 'img-' . $mode]])),
                    Text::create($label),
                    ...$labelDescription
                )
            ));
        }

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create([
                'class' => ['control-group']
            ]),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'control-label-group']),
                Text::create($this->translate('Rotation Mode'))
            ),
            $modeList
        ));

        return $value;
    }

    /**
     * Assemble option elements for the 24/7 mode
     *
     * @param FieldsetElement $options
     *
     * @return DateTime The default first handoff
     */
    protected function assembleTwentyFourSevenOptions(FieldsetElement $options): DateTime
    {
        $options->addElement('number', 'interval', [
            'required' => true,
            'label' => $this->translate('Handoff every'),
            'description' => $this->translate('Have multiple rotation members take turns after this interval.'),
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'validators' => [new GreaterThanValidator()]
        ]);
        $interval = $options->getElement('interval');
        $interval->getDecorators()
            ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);

        $frequency = $options->createElement('select', 'frequency', [
            'required' => true,
            'options' => [
                'd' => $this->translate('Days'),
                'w' => $this->translate('Weeks')
            ]
        ]);
        $options->registerElement($frequency);

        $at = $options->createElement('select', 'at', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $this->getTimeOptions()
        ]);
        $options->registerElement($at);

        $interval->prependWrapper(
            (new HtmlDocument())->addHtml(
                $interval,
                $frequency,
                new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate('at', 'handoff every <interval> <frequency> at <time>'))
                ),
                $at
            )
        );

        $now = new DateTime();
        if (isset($this->rotation->previousShift) && $this->rotation->previousShift > $now) {
            $now = $this->rotation->previousShift;
        }

        $date = null;
        if (isset($this->rotation->previousHandoff) && $this->rotation->previousHandoff >= $now) {
            // Use the previous handoff as default, if it's still valid
            $date = $this->rotation->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->rotation->parseDateAndTime($date, $at->getValue());

        return $firstHandoff >= $now ? $firstHandoff : $firstHandoff->add(new DateInterval('P1D'));
    }

    /**
     * Assemble option elements for the partial day mode
     *
     * @param FieldsetElement $options
     *
     * @return DateTime The default first handoff
     */
    protected function assemblePartialDayOptions(FieldsetElement $options): DateTime
    {
        $options->addElement('select', 'days', [
            'required' => true,
            'multiple' => true,
            'class' => 'autosubmit',
            'label' => $this->translate('Days'),
            'value' => [1],
            'size' => 7,
            'options' => [
                1 => $this->translate('Monday'),
                2 => $this->translate('Tuesday'),
                3 => $this->translate('Wednesday'),
                4 => $this->translate('Thursday'),
                5 => $this->translate('Friday'),
                6 => $this->translate('Saturday'),
                7 => $this->translate('Sunday')
            ]
        ]);

        $timeOptions = $this->getTimeOptions();
        $options->addElement('select', 'from', [
            'class' => 'autosubmit',
            'required' => true,
            'value' => '00:00',
            'options' => $timeOptions,
            'label' => $this->translate('From')
        ]);
        $from = $options->getElement('from');

        $options->addElement('number', 'interval', [
            'required' => true,
            'label' => $this->translate('Handoff every'),
            'description' => $this->translate('Have multiple rotation members take turns after this interval.'),
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'validators' => [new GreaterThanValidator()]
        ]);
        $interval = $options->getElement('interval');
        $interval->getDecorators()
            ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);

        $selectedFromTime = $from->getValue();
        $nextDayTimeOptions = [];
        foreach ($timeOptions as $key => $value) {
            unset($timeOptions[$key]);
            $nextDayTimeOptions[$key] = $value;

            if ($selectedFromTime === $key) {
                break;
            }
        }

        $to = $options->createElement('select', 'to', [
            'required' => true,
            'options' => empty($timeOptions)
                ? [$this->translate('Next Day') => $nextDayTimeOptions]
                : [$this->translate('Same Day') => $timeOptions, $this->translate('Next Day') => $nextDayTimeOptions]
        ]);
        $options->registerElement($to);

        $from->prependWrapper(
            (new HtmlDocument())->addHtml(
                $from,
                new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate('to', '<time> to <time>'))
                ),
                $to
            )
        );

        $interval->prependWrapper(
            (new HtmlDocument())->addHtml(
                $interval,
                new HtmlElement('span', null, Text::create($this->translate('Week(s)')))
            )
        );

        $now = new DateTime();
        if (isset($this->rotation->previousShift) && $this->rotation->previousShift > $now) {
            $now = $this->rotation->previousShift;
        }

        $date = null;
        if (isset($this->rotation->previousHandoff) && $this->rotation->previousHandoff >= $now) {
            // Use the previous handoff as default, if it's still valid
            $date = $this->rotation->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->rotation->parseDateAndTime($date, $from->getValue());
        $chosenDays = array_flip($options->getValue('days'));
        if ($firstHandoff < $now || ! isset($chosenDays[$firstHandoff->format('N')])) {
            $remainingAttempts = 7;

            do {
                $firstHandoff->add(new DateInterval('P1D'));
            } while ($remainingAttempts-- > 0 && ! isset($chosenDays[$firstHandoff->format('N')]));
        }

        return $firstHandoff;
    }

    /**
     * Assemble option elements for the multi day mode
     *
     * @param FieldsetElement $options
     *
     * @return DateTime The default first handoff
     */
    protected function assembleMultiDayOptions(FieldsetElement $options): DateTime
    {
        $fromDays = $toDays = [
            1 => $this->translate('Monday'),
            2 => $this->translate('Tuesday'),
            3 => $this->translate('Wednesday'),
            4 => $this->translate('Thursday'),
            5 => $this->translate('Friday'),
            6 => $this->translate('Saturday'),
            7 => $this->translate('Sunday')
        ];

        $options->addElement('select', 'from_day', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $fromDays,
            'value' => 1,
            'label' => $this->translate('From', 'notifications.rotation')
        ]);
        $from = $options->getElement('from_day');

        $selectedFromDay = (int) $from->getValue();

        for ($i = 1; $i <= $selectedFromDay; $i++) {
            $day = $toDays[$i];
            unset($toDays[$i]); // unset to re-add it at the end of array
            $toDays[$i] = sprintf('%s (%s)', $day, $this->translate('Next week'));
        }

        $options->addElement('select', 'to_day', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $toDays,
            'value' => 7,
            'label' => $this->translate('To', 'notifications.rotation')
        ]);
        $to = $options->getElement('to_day');

        $options->addElement('number', 'interval', [
            'required' => true,
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'label' => $this->translate('Handoff every'),
            'description' => $this->translate('Have multiple rotation members take turns after this interval.'),
            'validators' => [new GreaterThanValidator()]
        ]);
        $interval = $options->getElement('interval');
        $interval->getDecorators()
            ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);

        $timeOptions = $this->getTimeOptions();
        $fromAt = $options->createElement('select', 'from_at', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $timeOptions
        ]);
        $options->registerElement($fromAt);
        $selectedFromAt = $fromAt->getValue();

        // Small optimization only, out-of-range options are only required under certain conditions
        $removeOutOfRangeToAtOptions = function () use ($selectedFromAt, $timeOptions) {
            return array_slice(
                $timeOptions,
                0,
                array_search($selectedFromAt, array_keys($timeOptions), true) + 1,
                true
            );
        };

        $timeOptionsFirstKey = array_key_first($timeOptions);
        $selectedToDay = (int) $to->getValue();
        $endOfDay = 'endOfDay';
        if ($selectedFromDay === $selectedToDay) {
            $timeOptions = $removeOutOfRangeToAtOptions();
        } else {
            $timeOptions[$endOfDay] = sprintf(
                $this->translate('%s (End of day)'),
                $timeOptions[$timeOptionsFirstKey]
            );
        }

        /** @var SelectElement $toAt */
        $toAt = $options->createElement('select', 'to_at', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $timeOptions
        ]);
        $options->registerElement($toAt);

        if ($toAt->getValue() === $endOfDay) {
            $selectedToDay = $selectedToDay === 7 ? 1 : $selectedToDay + 1;

            if ($selectedFromDay === $selectedToDay) {
                $toAt->setOptions($removeOutOfRangeToAtOptions());
            }

            $to->setValue($selectedToDay);
            $toAt->setValue($timeOptionsFirstKey);
        }

        $from->prependWrapper(
            (new HtmlDocument())->addHtml(
                $from,
                new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate('at', 'from <dayname> at <time>'))
                ),
                $fromAt
            )
        );

        $to->prependWrapper(
            (new HtmlDocument())->addHtml(
                $to,
                new HtmlElement(
                    'span',
                    null,
                    Text::create($this->translate('at', 'from <dayname> at <time>'))
                ),
                $toAt
            )
        );

        $interval->prependWrapper(
            (new HtmlDocument())->addHtml(
                $interval,
                new HtmlElement('span', null, Text::create($this->translate('Week(s)')))
            )
        );

        $now = new DateTime();
        if (isset($this->rotation->previousShift) && $this->rotation->previousShift > $now) {
            $now = $this->rotation->previousShift;
        }

        $date = null;
        if (isset($this->rotation->previousHandoff) && $this->rotation->previousHandoff >= $now) {
            $date = $this->rotation->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->rotation->parseDateAndTime($date, $fromAt->getValue());
        $firstHandoffDayOfTheWeek = $firstHandoff->format('N');
        if ($firstHandoffDayOfTheWeek > $from->getValue()) {
            $firstHandoff->add(new DateInterval(
                sprintf('P%dD', 7 - $firstHandoffDayOfTheWeek + $from->getValue())
            ));
        } elseif ($firstHandoffDayOfTheWeek < $from->getValue()) {
            $firstHandoff->add(new DateInterval(
                sprintf('P%dD', $from->getValue() - $firstHandoffDayOfTheWeek)
            ));
        } elseif ($firstHandoff < $now) {
            $firstHandoff->add(new DateInterval('P1W'));
        }

        return $firstHandoff;
    }

    protected function assemble(): void
    {
        $this->getAttributes()->add('class', 'rotation-config');

        $this->addElement('hidden', 'priority', ['ignore' => true]);

        $this->addElement('text', 'name', [
            'required' => true,
            'label' => $this->translate('Rotation Name'),
            'validators' => [
                new CallbackValidator(function ($value, $validator) {
                    $rotations = Rotation::on(Database::get())
                        ->columns('id')
                        ->filter(Filter::equal('schedule_id', $this->scheduleId))
                        ->filter(Filter::equal('name', $value));
                    if (($priority = $this->getValue('priority')) !== null) {
                        $rotations->filter(Filter::unequal('priority', $priority));
                    }

                    if ($rotations->first() !== null) {
                        $validator->addMessage($this->translate('A rotation with this name already exists'));

                        return false;
                    }

                    return true;
                })
            ]
        ]);

        $termValidator = function (array $terms) {
            $contactTerms = [];
            $groupTerms = [];
            foreach ($terms as $term) {
                /** @var TermInput\Term $term */
                if (! str_contains($term->getSearchValue(), ':')) {
                    // TODO: Auto-correct this to a valid type:id pair, if possible
                    $term->setMessage($this->translate('Is not a contact nor a group of contacts'));
                    continue;
                }

                list($type, $id) = explode(':', $term->getSearchValue(), 2);
                if ($type === 'contact') {
                    $contactTerms[$id] = $term;
                } elseif ($type === 'group') {
                    $groupTerms[$id] = $term;
                }
            }

            if (! empty($contactTerms)) {
                $contacts = (Contact::on(Database::get()))
                    ->filter(Filter::equal('id', array_keys($contactTerms)));
                foreach ($contacts as $contact) {
                    $contactTerms[$contact->id]
                        ->setLabel($contact->full_name)
                        ->setClass('contact');
                }
            }

            if (! empty($groupTerms)) {
                $groups = (Contactgroup::on(Database::get()))
                    ->filter(Filter::equal('id', array_keys($groupTerms)));
                foreach ($groups as $group) {
                    $groupTerms[$group->id]
                        ->setLabel($group->name)
                        ->setClass('group');
                }
            }
        };

        $members = (new TermInput('members'))
            ->setIgnored()
            ->setRequired()
            ->setOrdered()
            ->setReadOnly()
            ->setVerticalTermDirection()
            ->setLabel($this->translate('Rotation Members'))
            ->setSuggestionUrl($this->suggestionUrl->with(['showCompact' => true, '_disableLayout' => 1]))
            ->on(TermInput::ON_ENRICH, $termValidator)
            ->on(TermInput::ON_ADD, $termValidator)
            ->on(TermInput::ON_SAVE, $termValidator)
            ->on(TermInput::ON_PASTE, $termValidator);
        $this->addElement($members);

        // TODO: TermInput is not compatible with the new decorators yet: https://github.com/Icinga/ipl-web/pull/317
        $legacyDecorator = new IcingaFormDecorator();
        $members->setDefaultElementDecorator($legacyDecorator);
        $legacyDecorator->decorate($members);

        $mode = $this->assembleModeSelection();

        $autoSubmittedBy = $this->getRequest()->getHeader('X-Icinga-Autosubmittedby')[0] ?? '';
        if ($autoSubmittedBy === 'mode') {
            $this->clearPopulatedValue('options');
            $this->clearPopulatedValue('first_handoff');
        }

        $this->addElement('fieldset', 'options');
        /** @var FieldsetElement $options */
        $options = $this->getElement('options');

        if ($mode === '24-7') {
            $firstHandoff = $this->assembleTwentyFourSevenOptions($options);
        } elseif ($mode === 'partial') {
            $firstHandoff = $this->assemblePartialDayOptions($options);
        } else {
            $firstHandoff = $this->assembleMultiDayOptions($options);
        }

        $now = new DateTime();
        $earliestHandoff = null;
        if (
            isset($this->rotation->previousHandoff) && $this->rotation->previousHandoff <= $now
            || isset($this->rotation->previousShift)
        ) {
            // If this rotation started already, someone is probably already on duty, so the next sensible
            // handoff is what the rotation mode already identified as default first handoff
            $earliestHandoff = $firstHandoff;
        }

        $latestHandoff = isset($this->rotation->nextHandoff)
            ? (clone $this->rotation->nextHandoff)->sub(new DateInterval('P1D'))
            : (clone $now)->add(new DateInterval('P30D'));

        $firstHandoffDefault = null;
        if (self::EXPERIMENTAL_OVERRIDES) {
            // TODO: May be incorrect if near the next handoff??
            $firstHandoffDefault = $firstHandoff->format('Y-m-d');
        }

        $this->addElement('input', 'first_handoff', [
            'class' => 'autosubmit',
            'type' => 'date',
            'required' => true,
            'aria-describedby' => 'first-handoff-description',
            'min' => $earliestHandoff?->format('Y-m-d'),
            'max' => $latestHandoff->format('Y-m-d'),
            'label' => $this->translate('Rotation Start'),
            'value' => $firstHandoffDefault,
            'validators' => [
                new CallbackValidator(
                    function ($value, $validator) use ($earliestHandoff, $firstHandoff, $latestHandoff) {
                        $chosenHandoff = $this->rotation->parseDateAndTime($value, $firstHandoff->format('H:i'));
                        $latestHandoff = $this->rotation->parseDateAndTime(
                            $latestHandoff->format('Y-m-d'),
                            $firstHandoff->format('H:i')
                        );
                        if ($earliestHandoff !== null && $chosenHandoff < $earliestHandoff) {
                            $validator->addMessage(sprintf(
                                $this->translate('The rotation can only start after %s'),
                                $earliestHandoff->format('Y-m-d') // TODO: Use intl here
                            ));

                            return false;
                        } elseif ($chosenHandoff > $latestHandoff) {
                            $validator->addMessage(sprintf(
                                $this->translate('The rotation can only start before %s'),
                                $latestHandoff->format('Y-m-d') // TODO: Use intl here
                            ));

                            return false;
                        }

                        return true;
                    }
                )
            ]
        ]);

        if ($this->getElement('first_handoff')->hasValue()) {
            $this->addHtml(new HtmlElement(
                'p',
                Attributes::create(['id' => 'first-handoff-description']),
                DeferredText::create(function () {
                    if (! $this->isValid()) {
                        return '';
                    }

                    $ruleGenerator = $this->applyChanges()->getRotation()->yieldRecurrenceRules(1);
                    if (! $ruleGenerator->valid()) {
                        return $this->translate('This rotation can no longer happen');
                    }

                    $actualFirstHandoff = $ruleGenerator->current()[0]->getStartDate();
                    if ($actualFirstHandoff < new DateTime()) {
                        return $this->translate('The rotation will start immediately');
                    } else {
                        return sprintf(
                            $this->translate('The rotation will start on %s'),
                            (new IntlDateFormatter(
                                Locale::getDefault(),
                                IntlDateFormatter::MEDIUM,
                                IntlDateFormatter::SHORT,
                                $this->scheduleTimezone
                            ))->format($actualFirstHandoff)
                        );
                    }
                }),
                new HtmlElement('br'),
                $this->displayTimezone !== $this->scheduleTimezone ? DeferredText::create(function () {
                    if (! $this->isValid()) {
                        return '';
                    }

                    $ruleGenerator = $this->applyChanges()->getRotation()->yieldRecurrenceRules(1);
                    if (! $ruleGenerator->valid()) {
                        return '';
                    }

                    $actualFirstHandoff = $ruleGenerator->current()[0]->getStartDate();
                    if ($actualFirstHandoff < new DateTime()) {
                        return '';
                    } else {
                        return sprintf(
                            $this->translate('In your chosen display timezone (%s) this is the %s'),
                            $this->displayTimezone,
                            (new IntlDateFormatter(
                                Locale::getDefault(),
                                IntlDateFormatter::MEDIUM,
                                IntlDateFormatter::SHORT,
                                $this->displayTimezone
                            ))->format($actualFirstHandoff)
                        );
                    }
                }) : new HtmlDocument()
            ));
        }

        $this->addElement('submit', 'submit', [
            'label' => $this->getSubmitLabel()
        ]);

        if ($this->showRemoveButton) {
            $removeButtons = [];
            if (isset($this->rotation->previousShift) || isset($this->rotation->nextHandoff)) {
                $removeAllBtn = $this->createElement('submit', 'remove_all', [
                    'label' => $this->translate('Remove All'),
                    'class' => 'btn-remove',
                    'formnovalidate' => true
                ]);
                $this->registerElement($removeAllBtn);
                $removeButtons[] = $removeAllBtn;
            }

            $removeBtn = $this->createElement('submit', 'remove', [
                'label' => $this->translate('Remove'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);
            $removeButtons[] = $removeBtn;

            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent(...$removeButtons));
        }

        $this->addCsrfCounterMeasure(Session::getSession()->getId());
    }

    /**
     * Get the options for the time select elements
     *
     * @return array<string, string>
     */
    private function getTimeOptions(): array
    {
        $formatter = new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::SHORT,
            $this->scheduleTimezone
        );

        $options = [];
        $dt = new DateTime('now', new DateTimeZone($this->scheduleTimezone));
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 30) {
                $dt->setTime($hour, $minute);
                $options[$dt->format('H:i')] = $formatter->format($dt);
            }
        }

        return $options;
    }

    /**
     * Transform the current rotation into form data
     *
     * @return array
     */
    private function rotationToFormData(): array
    {
        $formData = [
            'mode' => $this->rotation->mode,
            'name' => $this->rotation->name,
            'priority' => $this->rotation->priority,
            'schedule' => $this->rotation->schedule_id,
            'options' => $this->rotation->options
        ];
        if (! self::EXPERIMENTAL_OVERRIDES) {
            $formData['first_handoff'] = $this->rotation->first_handoff;
        }

        $members = [];
        foreach ($this->rotation->member->orderBy('position', SORT_ASC) as $member) {
            if ($member->contact_id !== null) {
                $members[] = 'contact:' . $member->contact_id;
            } else {
                $members[] = 'group:' . $member->contactgroup_id;
            }
        }

        $formData['members'] = implode(',', $members);

        return $formData;
    }

    /**
     * Whether the form has changes
     *
     * @return bool
     */
    private function hasChanges(): bool
    {
        $values = $this->getValues();
        $values['members'] = $this->getValue('members');

        // only keys that are present in $values
        $dbValuesToCompare = array_intersect_key($this->rotationToFormData(), $values);

        $checker = static function ($a, $b) use (&$checker) {
            if (! is_array($a) || ! is_array($b)) {
                return $a <=> $b;
            }

            return empty(array_udiff_assoc($a, $b, $checker)) ? 0 : 1;
        };

        return ! empty(array_udiff_assoc($values, $dbValuesToCompare, $checker));
    }

    /**
     * Apply the user's changes to the rotation
     *
     * @return $this
     */
    private function applyChanges(): static
    {
        // TODO: ! $this->rotation->isNew()
        if (! $this->hasChanges()) {
            return $this;
        }

        $this->rotation->schedule_id = $this->scheduleId;
        $this->rotation->priority = $this->getValue('priority', 0);
        $this->rotation->name = $this->getValue('name');
        $this->rotation->mode = $this->getValue('mode');
        $this->rotation->options = Json::encode($this->getValue('options'));
        $this->rotation->first_handoff = $this->getValue('first_handoff');

        $members = [];
        foreach (explode(',', $this->getValue('members')) as $i => $memberDef) {
            [$type, $id] = explode(':', $memberDef, 2);

            $members[] = match ($type) {
                'contact' => new RotationMember([
                    'rotation_id' => $this->rotation->id ?? null,
                    'contact_id' => $id,
                    'contactgroup_id' => null,
                    'position' => $i
                ]),
                'contactgroup' => new RotationMember([
                    'rotation_id' => $this->rotation->id ?? null,
                    'contact_id' => null,
                    'contactgroup_id' => $id,
                    'position' => $i
                ])
            };
        }

        $this->rotation->member = new ResultSet(new ArrayIterator($members));

        return $this;
    }
}
