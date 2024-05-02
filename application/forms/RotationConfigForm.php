<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\GreaterThanValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Url;

class RotationConfigForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var ?int The ID of the affected schedule */
    protected $scheduleId;

    /** @var string The label shown on the submit button */
    protected $submitLabel;

    /** @var bool Whether to render the remove button */
    protected $showRemoveButton = false;

    /** @var Url The URL to fetch member suggestions from */
    protected $suggestionUrl;

    /** @var bool Whether the mode selection is disabled */
    protected $disableModeSelection = false;

    /**
     * Set the label for the submit button
     *
     * @param string $label
     *
     * @return $this
     */
    public function setSubmitLabel(string $label): self
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
    public function setShowRemoveButton(bool $state = true): self
    {
        $this->showRemoveButton = $state;

        return $this;
    }

    /**
     * Set the URL to fetch member suggestions from
     *
     * @param Url $url
     *
     * @return void
     */
    public function setSuggestionUrl(Url $url): self
    {
        $this->suggestionUrl = $url;

        return $this;
    }

    /**
     * Disable the mode selection
     *
     * @return void
     */
    public function disableModeSelection(): self
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
     */
    public function __construct(int $scheduleId)
    {
        $this->scheduleId = $scheduleId;
    }

    protected function assembleModeSelection(): string
    {
        $value = $this->getPopulatedValue('mode');

        $modes = [
            '24-7' => $this->translate('24/7'),
            'partial' => $this->translate('Partial Day'),
            'multi' => $this->translate('Multi Day')
        ];

        $modeList = new HtmlElement('ul');
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

            $modeList->addHtml(new HtmlElement(
                'li',
                null,
                new HtmlElement(
                    'label',
                    null,
                    $radio,
                    new HtmlElement('img', Attributes::create([
                        'src' => Url::fromPath(sprintf('img/notifications/pictogram/%s-gray.jpg', $mode)),
                        'class' => 'unchecked'
                    ])),
                    new HtmlElement('img', Attributes::create([
                        'src' => Url::fromPath(sprintf('img/notifications/pictogram/%s-colored.jpg', $mode)),
                        'class' => 'checked'
                    ])),
                    Text::create($label)
                )
            ));
        }

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => ['rotation-mode', $this->disableModeSelection ? 'disabled' : '']]),
            new HtmlElement('h2', null, Text::create($this->translate('Mode'))),
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
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'validators' => [new GreaterThanValidator()]
        ]);
        $interval = $options->getElement('interval');

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

        $firstHandoff = $this->parseDateAndTime(null, $at->getValue());

        return $firstHandoff >= new DateTime() ? $firstHandoff : $firstHandoff->add(new DateInterval('P1D'));
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
        $options->addElement('select', 'from', [
            'class' => 'autosubmit',
            'required' => true,
            'value' => '00:00',
            'options' => $this->getTimeOptions(),
            'label' => $this->translate('From')
        ]);
        $from = $options->getElement('from');

        $options->addElement('number', 'interval', [
            'required' => true,
            'label' => $this->translate('Handoff every'),
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'validators' => [new GreaterThanValidator()]
        ]);

        $to = $options->createElement('select', 'to', [
            'required' => true,
            'options' => $this->getTimeOptions()
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

        $interval = $options->getElement('interval');
        $interval->prependWrapper(
            (new HtmlDocument())->addHtml(
                $interval,
                new HtmlElement('span', null, Text::create($this->translate('Week(s)')))
            )
        );

        $firstHandoff = $this->parseDateAndTime(null, $from->getValue());
        $chosenDays = array_flip($options->getValue('days'));
        if ($firstHandoff <= new DateTime() || ! isset($chosenDays[$firstHandoff->format('N')])) {
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

        $options->addElement('select', 'to_day', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $toDays,
            'value' => 7,
            'label' => $this->translate('To', 'notifications.rotation'),
            'validators' => [
                new CallbackValidator(function ($value, $validator) use ($options) {
                    if ($value !== $options->getValue('from_day')) {
                        return true;
                    }

                    if ($options->getValue('from_at') < $options->getValue('to_at')) {
                        $validator->addMessage($this->translate('Shifts cannot last longer than 7 days'));

                        return false;
                    }

                    return true;
                })
            ]
        ]);
        $to = $options->getElement('to_day');

        $options->addElement('number', 'interval', [
            'required' => true,
            'step' => 1,
            'min' => 1,
            'value' => 1,
            'label' => $this->translate('Handoff every')
        ]);

        $fromAt = $options->createElement('select', 'from_at', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $this->getTimeOptions()
        ]);
        $options->registerElement($fromAt);

        $toAt = $options->createElement('select', 'to_at', [
            'class' => 'autosubmit',
            'required' => true,
            'options' => $this->getTimeOptions()
        ]);
        $options->registerElement($toAt);

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

        $interval = $options->getElement('interval');
        $interval->prependWrapper(
            (new HtmlDocument())->addHtml(
                $interval,
                new HtmlElement('span', null, Text::create($this->translate('Week(s)')))
            )
        );

        $firstHandoff = $this->parseDateAndTime(null, $fromAt->getValue());
        $firstHandoffDayOfTheWeek = $firstHandoff->format('N');
        if ($firstHandoffDayOfTheWeek > $from->getValue()) {
            $firstHandoff->add(new DateInterval(
                sprintf('P%dD', 7 - $firstHandoffDayOfTheWeek + $from->getValue())
            ));
        } elseif ($firstHandoffDayOfTheWeek < $from->getValue()) {
            $firstHandoff->add(new DateInterval(
                sprintf('P%dD', $from->getValue() - $firstHandoffDayOfTheWeek)
            ));
        } elseif ($firstHandoff < new DateTime()) {
            $firstHandoff->add(new DateInterval('P1W'));
        }

        return $firstHandoff;
    }

    protected function assemble()
    {
        $this->getAttributes()->add('class', 'rotation-config');

        $mode = $this->assembleModeSelection();

        $autoSubmittedBy = $this->getRequest()->getHeader('X-Icinga-Autosubmittedby')[0] ?? '';
        if ($autoSubmittedBy === 'mode') {
            $this->clearPopulatedValue('options');
            $this->clearPopulatedValue('first_handoff');
        }

        $this->addElement('text', 'name', [
            'required' => true,
            'label' => $this->translate('Title'),
            'validators' => [
                new CallbackValidator(function ($value, $validator) {
                    $rotations = Rotation::on($this->db)
                        ->columns('id')
                        ->filter(Filter::equal('schedule_id', $this->scheduleId))
                        ->filter(Filter::equal('name', $value));
                    if ($rotations->first() !== null) {
                        $validator->addMessage($this->translate('A rotation with this title already exists'));

                        return false;
                    }

                    return true;
                })
            ]
        ]);

        $options = new FieldsetElement('options');
        $this->addElement($options);

        if ($mode === '24-7') {
            $firstHandoff = $this->assembleTwentyFourSevenOptions($options);
        } elseif ($mode === 'partial') {
            $firstHandoff = $this->assemblePartialDayOptions($options);
        } else {
            $firstHandoff = $this->assembleMultiDayOptions($options);
        }

        $this->addElement('input', 'first_handoff', [
            'type' => 'date',
            'required' => true,
            'min' => $firstHandoff->format('Y-m-d'),
            'max' => (new DateTime())->add(new DateInterval('P30D'))->format('Y-m-d'),
            'label' => $this->translate('First Handoff'),
            'value' => $firstHandoff->format('Y-m-d'),
            'validators' => [
                new CallbackValidator(function ($value, $validator) use ($firstHandoff) {
                    $chosenHandoff = $this->parseDateAndTime($value, $firstHandoff->format('H:i'));
                    if ($chosenHandoff < $firstHandoff) {
                        $validator->addMessage(sprintf(
                            $this->translate('The first handoff can only happen after %s'),
                            $firstHandoff->format('Y-m-d') // TODO: Use intl here
                        ));

                        return false;
                    } elseif ($chosenHandoff > (new DateTime())->add(new DateInterval('P30D'))) {
                        $validator->addMessage(sprintf(
                            $this->translate('The first handoff can only happen before %s'),
                            (new DateTime())->add(new DateInterval('P30D'))->format('Y-m-d') // TODO: Use intl here
                        ));

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
                if (strpos($term->getSearchValue(), ':') === false) {
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

        $this->addElement(
            (new TermInput('members'))
                ->setIgnored()
                ->setRequired()
                ->setOrdered()
                ->setReadOnly()
                ->setVerticalTermDirection()
                ->setLabel($this->translate('Members'))
                ->setSuggestionUrl($this->suggestionUrl->with(['showCompact' => true, '_disableLayout' => 1]))
                ->on(TermInput::ON_ENRICH, $termValidator)
                ->on(TermInput::ON_ADD, $termValidator)
                ->on(TermInput::ON_SAVE, $termValidator)
                ->on(TermInput::ON_PASTE, $termValidator)
        );

        $this->addElement('submit', 'submit', [
            'label' => $this->getSubmitLabel()
        ]);

        if ($this->showRemoveButton) {
            $removeButtons = [];
            if ($this->previousShift !== null || $this->nextHandoff !== null) {
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

        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
    }

    /**
     * Parse the given date and time expression
     *
     * @param ?string $date A date in the format Y-m-d, default is the current day
     * @param ?string $time The time in the format H:i, default is midnight
     *
     * @return DateTime
     */
    private function parseDateAndTime(?string $date = null, ?string $time = null): DateTime
    {
        $format = '';
        $expression = '';

        if ($date !== null) {
            $format = 'Y-m-d';
            $expression = $date;
        }

        if ($time !== null) {
            if ($date !== null) {
                $format .= ' ';
                $expression .= ' ';
            }

            $format .= 'H:i';
            $expression .= $time;
        }

        if (! $format) {
            return (new DateTime())->setTime(0, 0);
        }

        $datetime = DateTime::createFromFormat($format, $expression);
        if ($datetime === false) {
            $datetime = (new DateTime())->setTime(0, 0);
        } elseif ($time === null) {
            $datetime->setTime(0, 0);
        }

        return $datetime;
    }

    /**
     * Get the options for the time select elements
     *
     * @return array<string, string>
     */
    private function getTimeOptions(): array
    {
        $formatter = new \IntlDateFormatter(
            \Locale::getDefault(),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT
        );

        $options = [];
        $dt = new DateTime();
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 30) {
                $dt->setTime($hour, $minute);
                $options[$dt->format('H:i')] = $formatter->format($dt);
            }
        }

        return $options;
    }
}
