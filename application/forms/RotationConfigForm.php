<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateInterval;
use DateTime;
use Generator;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Util\Json;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\DeferredText;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\GreaterThanValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Url;
use LogicException;
use Recurr\Frequency;
use Recurr\Rule;

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

    /** @var ?int The ID of the affected schedule */
    protected $scheduleId;

    /** @var Connection The database connection */
    protected $db;

    /** @var string The label shown on the submit button */
    protected $submitLabel;

    /** @var bool Whether to render the remove button */
    protected $showRemoveButton = false;

    /** @var Url The URL to fetch member suggestions from */
    protected $suggestionUrl;

    /** @var bool Whether the mode selection is disabled */
    protected $disableModeSelection = false;

    /** @var ?DateTime The previous first handoff of this rotation's version */
    protected $previousHandoff;

    /** @var ?DateTime The end of the last shift of this rotation's previous version */
    protected $previousShift;

    /** @var ?DateTime The first handoff of a newer version for this rotation */
    protected $nextHandoff;

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
     * @param Connection $db
     */
    public function __construct(int $scheduleId, Connection $db)
    {
        $this->db = $db;
        $this->scheduleId = $scheduleId;
    }

    /**
     * Load the rotation with the given ID from the database
     *
     * @param int $rotationId
     *
     * @return $this
     * @throws HttpNotFoundException If the rotation with the given ID does not exist
     */
    public function loadRotation(int $rotationId): self
    {
        /** @var ?Rotation $rotation */
        $rotation = Rotation::on($this->db)
            ->filter(Filter::all(
                Filter::equal('id', $rotationId),
                Filter::equal('deleted', 'n')
            ))
            ->first();
        if ($rotation === null) {
            throw new HttpNotFoundException($this->translate('Rotation not found'));
        }

        $formData = [
            'mode' => $rotation->mode,
            'name' => $rotation->name,
            'priority' => $rotation->priority,
            'schedule' => $rotation->schedule_id,
            'options' => $rotation->options
        ];
        if (! self::EXPERIMENTAL_OVERRIDES) {
            $formData['first_handoff'] = $rotation->first_handoff;
        }

        if (self::EXPERIMENTAL_OVERRIDES) {
            $getHandoff = function (Rotation $rotation): DateTime {
                switch ($rotation->mode) {
                    case '24-7':
                        $time = $rotation->options['at'];

                        break;
                    case 'partial':
                        $time = $rotation->options['from'];

                        break;
                    case 'multi':
                        $time = $rotation->options['from_at'];

                        break;
                    default:
                        throw new LogicException('Invalid mode');
                }

                $handoff = DateTime::createFromFormat('Y-m-d H:i', $rotation->first_handoff . ' ' . $time);
                if ($handoff === false) {
                    throw new ConfigurationError('Invalid date format');
                }

                return $handoff;
            };

            $this->previousHandoff = $getHandoff($rotation);

            /** @var ?TimeperiodEntry $previousShift */
            $previousShift = TimeperiodEntry::on($this->db)
                ->columns('until_time')
                ->filter(Filter::all(
                    Filter::equal('deleted', 'n'),
                    Filter::equal('timeperiod.deleted', 'n'),
                    Filter::equal('timeperiod.rotation.schedule_id', $rotation->schedule_id),
                    Filter::equal('timeperiod.rotation.priority', $rotation->priority),
                    Filter::unequal('timeperiod.owned_by_rotation_id', $rotation->id),
                    Filter::lessThanOrEqual('until_time', $this->previousHandoff),
                    Filter::like('until_time', '*')
                ))
                ->orderBy('until_time', SORT_DESC)
                ->first();
            if ($previousShift !== null) {
                $this->previousShift = $previousShift->until_time;
            }

            /** @var ?Rotation $newerRotation */
            $newerRotation = Rotation::on($this->db)
                ->columns(['first_handoff', 'options', 'mode'])
                ->filter(Filter::all(
                    Filter::equal('deleted', 'n'),
                    Filter::equal('schedule_id', $rotation->schedule_id),
                    Filter::equal('priority', $rotation->priority),
                    Filter::greaterThan('first_handoff', $rotation->first_handoff)
                ))
                ->orderBy('first_handoff', SORT_ASC)
                ->first();
            if ($newerRotation !== null) {
                $this->nextHandoff = $getHandoff($newerRotation);
            }
        }

        $membersRes = $rotation
            ->member
            ->filter(Filter::equal('deleted', 'n'))
            ->filter(Filter::any(
                Filter::equal('contact.deleted', 'n'),
                Filter::equal('contactgroup.deleted', 'n')
            ))
            ->orderBy('position', SORT_ASC);

        $members = [];
        foreach ($membersRes as $member) {
            if ($member->contact_id !== null) {
                $members[] = 'contact:' . $member->contact_id;
            } else {
                $members[] = 'group:' . $member->contactgroup_id;
            }
        }

        $formData['members'] = implode(',', $members);

        $this->populate($formData);

        return $this;
    }

    /**
     * Insert a new rotation in the database
     *
     * @param int $priority The priority of the rotation
     *
     * @return Generator<int, DateTime> The first handoff of the rotation, as value
     */
    private function createRotation(int $priority): Generator
    {
        $data = $this->getValues();
        $data['options'] = Json::encode($data['options']);
        $data['schedule_id'] = $this->scheduleId;
        $data['priority'] = $priority;

        $members = array_map(function ($member) {
            return explode(':', $member, 2);
        }, explode(',', $this->getValue('members')));

        $rules = $this->yieldRecurrenceRules(count($members));
        $firstHandoff = $rules->current()[0]->getStartDate();

        // Only continue, once the caller is ready
        if (! yield $firstHandoff) {
            return;
        }

        $now = new DateTime();
        if ($firstHandoff < $now) {
            $data['actual_handoff'] = (int) $now->format('U.u') * 1000.0;
        } else {
            $data['actual_handoff'] = $firstHandoff->format('U.u') * 1000.0;
        }

        $this->db->insert('rotation', $data);
        $rotationId = $this->db->lastInsertId();

        $this->db->insert('timeperiod', ['owned_by_rotation_id' => $rotationId]);
        $timeperiodId = $this->db->lastInsertId();

        $knownMembers = [];
        foreach ($rules as $position => [$rrule, $shiftDuration]) {
            /** @var Rule $rrule */
            /** @var DateInterval $shiftDuration */

            if (isset($knownMembers[$position])) {
                $memberId = $knownMembers[$position];
            } else {
                [$type, $id] = $members[$position];

                if ($type === 'contact') {
                    $this->db->insert('rotation_member', [
                        'rotation_id' => $rotationId,
                        'contact_id' => $id,
                        'position' => $position
                    ]);
                } elseif ($type === 'group') {
                    $this->db->insert('rotation_member', [
                        'rotation_id' => $rotationId,
                        'contactgroup_id' => $id,
                        'position' => $position
                    ]);
                }

                $memberId = $this->db->lastInsertId();
                $knownMembers[$position] = $memberId;
            }

            $endTime = (clone $rrule->getStartDate())->add($shiftDuration)->format('U.u') * 1000.0;

            $untilTime = null;
            if (! $rrule->repeatsIndefinitely()) {
                // Our recurrence rules only repeat definitely due to a set until time
                $untilTime = (clone $rrule->getUntil())->add($shiftDuration)->format('U.u') * 1000.0;
            }

            $this->db->insert('timeperiod_entry', [
                'timeperiod_id' => $timeperiodId,
                'rotation_member_id' => $memberId,
                'start_time' => $rrule->getStartDate()->format('U.u') * 1000.0,
                'end_time' => $endTime,
                'until_time' => $untilTime,
                'timezone' => $rrule->getStartDate()->getTimezone()->getName(),
                'rrule' => $rrule->getString(Rule::TZ_FIXED),
            ]);
        }
    }

    /**
     * Add a new rotation to the database
     *
     * @return void
     */
    public function addRotation(): void
    {
        $transactionStarted = false;
        if (! $this->db->inTransaction()) {
            $transactionStarted = $this->db->beginTransaction();
        }

        $this->createRotation($this->db->fetchScalar(
            (new Select())
                ->from('rotation')
                ->columns(new Expression('MAX(priority) + 1'))
                ->where([
                    'schedule_id = ?'   => $this->scheduleId,
                    'deleted = ?'       => 'n',
                ])
        ) ?? 0)->send(true);

        if ($transactionStarted) {
            $this->db->commitTransaction();
        }
    }

    /**
     * Update the rotation with the given ID in the database
     *
     * @param int $rotationId
     *
     * @return void
     */
    public function editRotation(int $rotationId): void
    {
        $priority = $this->getValue('priority');
        if ($priority === null) {
            throw new LogicException('The priority must be populated');
        }

        $transactionStarted = false;
        if (! $this->db->inTransaction()) {
            $transactionStarted = $this->db->beginTransaction();
        }

        // Delay the creation, avoids intermediate constraint failures
        $createStmt = $this->createRotation((int) $priority);

        $allEntriesRemoved = true;
        $changedAt = time() * 1000;
        if (self::EXPERIMENTAL_OVERRIDES) {
            // We only show a single name, even in case of multiple versions of a rotation.
            // To avoid confusion, we update all versions upon change of the name
            $this->db->update('rotation',
                ['name' => $this->getValue('name'), 'changed_at' => $changedAt],
                ['schedule_id = ?' => $this->scheduleId, 'priority = ?' => $priority]
            );

            $firstHandoff = $createStmt->current();
            $timeperiodEntries = TimeperiodEntry::on($this->db)
                ->filter(Filter::all(
                    Filter::equal('deleted', 'n'),
                    Filter::equal('timeperiod.deleted', 'n'),
                    Filter::equal('timeperiod.owned_by_rotation_id', $rotationId)
                ));

            foreach ($timeperiodEntries as $timeperiodEntry) {
                /** @var TimeperiodEntry $timeperiodEntry */
                $rrule = $timeperiodEntry->toRecurrenceRule();
                $shiftDuration = $timeperiodEntry->start_time->diff($timeperiodEntry->end_time);
                $remainingHandoffs = $this->calculateRemainingHandoffs($rrule, $shiftDuration, $firstHandoff);
                $lastHandoff = array_shift($remainingHandoffs);

                // If there is a gap between the last handoff and the next one, insert a single occurrence to fill it
                if (! empty($remainingHandoffs)) {
                    [$gapStart, $gapEnd] = $remainingHandoffs[0];

                    $allEntriesRemoved = false;
                    $this->db->insert('timeperiod_entry', [
                        'timeperiod_id' => $timeperiodEntry->timeperiod_id,
                        'rotation_member_id' => $timeperiodEntry->rotation_member_id,
                        'start_time' => $gapStart->format('U.u') * 1000.0,
                        'end_time' => $gapEnd->format('U.u') * 1000.0,
                        'until_time' => $gapEnd->format('U.u') * 1000.0,
                        'timezone' => $gapStart->getTimezone()->getName()
                    ]);
                }

                $lastShiftEnd = null;
                if ($lastHandoff !== null) {
                    $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
                }

                if ($lastHandoff === null) {
                    // If the handoff didn't happen at all, the entry can safely be removed
                    $this->db->delete('timeperiod_entry', ['id = ?' => $timeperiodEntry->id]);
                } else {
                    $allEntriesRemoved = false;
                    $this->db->update('timeperiod_entry', [
                        'until_time'    => $lastShiftEnd->format('U.u') * 1000.0,
                        'rrule'         => $rrule->setUntil($lastHandoff)->getString(Rule::TZ_FIXED),
                        'changed_at'    => $changedAt
                    ], ['id = ?' => $timeperiodEntry->id]);
                }
            }
        } else {
            $this->db->delete('timeperiod_entry', [
                'timeperiod_id = ?' => (new Select())
                    ->from('timeperiod')
                    ->columns('id')
                    ->where(['owned_by_rotation_id = ?' => $rotationId])
            ]);
        }

        if ($allEntriesRemoved) {
            $this->db->delete('timeperiod', ['owned_by_rotation_id = ?' => $rotationId]);
            $this->db->delete('rotation_member', ['rotation_id = ?' => $rotationId]);
            $this->db->delete('rotation', ['id = ?' => $rotationId]);
        }

        // Once constraint failures are impossible, create the new version
        $createStmt->send(true);

        if ($transactionStarted) {
            $this->db->commitTransaction();
        }
    }

    /**
     * Remove the rotation's version with the given ID from the database
     *
     * @param int $id
     *
     * @return void
     */
    public function removeRotation(int $id): void
    {
        $priority = $this->getValue('priority');
        if ($priority === null) {
            throw new LogicException('The priority must be populated');
        }

        $transactionStarted = false;
        if (! $this->db->inTransaction()) {
            $transactionStarted = $this->db->beginTransaction();
        }

        $timeperiodId = $this->db->fetchScalar(
            (new Select())
                ->from('timeperiod')
                ->columns('id')
                ->where([
                    'owned_by_rotation_id = ?'  => $id,
                    'deleted = ?'               => 'n',
                ])
        );

        $changedAt = time() * 1000;
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];

        $this->db->update('timeperiod_entry', $markAsDeleted, ['timeperiod_id = ?' => $timeperiodId]);
        $this->db->update('timeperiod', $markAsDeleted, ['id = ?' => $timeperiodId]);
        $this->db->update('rotation_member', $markAsDeleted + ['position' => null], ['rotation_id = ?' => $id]);

        $this->db->update(
            'rotation',
            $markAsDeleted + ['priority' => null, 'first_handoff' => null],
            ['id = ?' => $id]
        );

        $rotations = Rotation::on($this->db)
            ->filter(Filter::all(
                Filter::equal('deleted', 'n'),
                Filter::equal('schedule_id', $this->scheduleId),
                Filter::equal('priority', $priority)
            ));
        if ($rotations->count() === 0) {
            $affectedRotations = $this->db->select(
                (new Select())
                    ->columns('id')
                    ->from('rotation')
                    ->where([
                        'deleted = ?'       => 'n',
                        'schedule_id = ?'   => $this->scheduleId,
                        'priority > ?'      => $priority
                    ])
                    ->orderBy('priority ASC')
            );
            foreach ($affectedRotations as $rotation) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                    ['id = ?' => $rotation->id]
                );
            }
        }

        if ($transactionStarted) {
            $this->db->commitTransaction();
        }
    }

    /**
     * Remove all versions of the rotation from the database
     *
     * @return void
     */
    public function wipeRotation(int $priority = null): void
    {
        $priority = $priority ?? $this->getValue('priority');
        if ($priority === null) {
            throw new LogicException('The priority must be populated');
        }

        $transactionStarted = false;
        if (! $this->db->inTransaction()) {
            $transactionStarted = $this->db->beginTransaction();
        }

        $rotations = Rotation::on($this->db)
            ->columns('id')
            ->filter(Filter::all(
                Filter::equal('deleted', 'n'),
                Filter::equal('schedule_id', $this->scheduleId),
                Filter::equal('priority', $priority)
            ));

        $changedAt = time() * 1000;
        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];

        foreach ($rotations as $rotation) {
            $timeperiodId = $this->db->fetchScalar(
                (new Select())
                    ->from('timeperiod')
                    ->columns('id')
                    ->where(['owned_by_rotation_id = ?' => $rotation->id])
            );

            $this->db->update('timeperiod_entry', $markAsDeleted, ['timeperiod_id = ?' => $timeperiodId]);
            $this->db->update('timeperiod', $markAsDeleted, ['id = ?' => $timeperiodId]);
            $this->db->update(
                'rotation_member',
                $markAsDeleted + ['position' => null],
                ['rotation_id = ?' => $rotation->id]
            );

            $this->db->update(
                'rotation',
                $markAsDeleted + ['priority' => null, 'first_handoff' => null],
                ['id = ?' => $rotation->id]
            );
        }

        $affectedRotations = $this->db->select(
            (new Select())
                ->columns('id')
                ->from('rotation')
                ->where([
                    'deleted = ?'       => 'n',
                    'schedule_id = ?'   => $this->scheduleId,
                    'priority > ?'      => $priority
                ])
                ->orderBy('priority ASC')
        );
        foreach ($affectedRotations as $rotation) {
            $this->db->update(
                'rotation',
                ['priority' => new Expression('priority - 1'), 'changed_at' => $changedAt],
                ['id = ?' => $rotation->id]
            );
        }

        if ($transactionStarted) {
            $this->db->commitTransaction();
        }
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

        $now = new DateTime();
        if ($this->previousShift !== null && $this->previousShift > $now) {
            $now = $this->previousShift;
        }

        $date = null;
        if ($this->previousHandoff !== null && $this->previousHandoff >= $now) {
            // Use the previous handoff as default, if it's still valid
            $date = $this->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->parseDateAndTime($date, $at->getValue());

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

        $now = new DateTime();
        if ($this->previousShift !== null && $this->previousShift > $now) {
            $now = $this->previousShift;
        }

        $date = null;
        if ($this->previousHandoff !== null && $this->previousHandoff >= $now) {
            // Use the previous handoff as default, if it's still valid
            $date = $this->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->parseDateAndTime($date, $from->getValue());
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

        $now = new DateTime();
        if ($this->previousShift !== null && $this->previousShift > $now) {
            $now = $this->previousShift;
        }

        $date = null;
        if ($this->previousHandoff !== null && $this->previousHandoff >= $now) {
            $date = $this->previousHandoff->format('Y-m-d');
        }

        $firstHandoff = $this->parseDateAndTime($date, $fromAt->getValue());
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

    protected function assemble()
    {
        $this->getAttributes()->add('class', 'rotation-config');

        $this->addElement('hidden', 'priority', ['ignore' => true]);

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
                        ->filter(Filter::equal('deleted', 'n'))
                        ->filter(Filter::equal('schedule_id', $this->scheduleId))
                        ->filter(Filter::equal('name', $value));
                    if (($priority = $this->getValue('priority')) !== null) {
                        $rotations->filter(Filter::unequal('priority', $priority));
                    }

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

        $now = new DateTime();
        $earliestHandoff = null;
        if ($this->previousHandoff !== null && $this->previousHandoff <= $now || $this->previousShift !== null) {
            // If this rotation started already, someone is probably already on duty, so the next sensible
            // handoff is what the rotation mode already identified as default first handoff
            $earliestHandoff = $firstHandoff;
        }

        $latestHandoff = $this->nextHandoff
            ? (clone $this->nextHandoff)->sub(new DateInterval('P1D'))
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
            'min' => $earliestHandoff !== null ? $earliestHandoff->format('Y-m-d') : null,
            'max' => $latestHandoff->format('Y-m-d'),
            'label' => $this->translate('First Handoff'),
            'value' => $firstHandoffDefault,
            'validators' => [
                new CallbackValidator(
                    function ($value, $validator) use ($earliestHandoff, $firstHandoff, $latestHandoff) {
                        $chosenHandoff = $this->parseDateAndTime($value, $firstHandoff->format('H:i'));
                        $latestHandoff = $this->parseDateAndTime(
                            $latestHandoff->format('Y-m-d'),
                            $firstHandoff->format('H:i')
                        );
                        if ($earliestHandoff !== null && $chosenHandoff < $earliestHandoff) {
                            $validator->addMessage(sprintf(
                                $this->translate('The first handoff can only happen after %s'),
                                $earliestHandoff->format('Y-m-d') // TODO: Use intl here
                            ));

                            return false;
                        } elseif ($chosenHandoff > $latestHandoff) {
                            $validator->addMessage(sprintf(
                                $this->translate('The first handoff can only happen before %s'),
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
                    $ruleGenerator = $this->yieldRecurrenceRules(1);
                    if (! $ruleGenerator->valid()) {
                        return $this->translate('This rotation can no longer happen');
                    }

                    $actualFirstHandoff = $ruleGenerator->current()[0]->getStartDate();
                    if ($actualFirstHandoff < new DateTime()) {
                        return $this->translate('The first handoff will happen immediately');
                    } else {
                        return sprintf(
                            $this->translate('The first handoff will happen on %s'),
                            (new \IntlDateFormatter(
                                \Locale::getDefault(),
                                \IntlDateFormatter::MEDIUM,
                                \IntlDateFormatter::SHORT
                            ))->format($actualFirstHandoff)
                        );
                    }
                })
            ));
        }

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
                    ->filter(Filter::equal('deleted', 'n'))
                    ->filter(Filter::equal('id', array_keys($contactTerms)));
                foreach ($contacts as $contact) {
                    $contactTerms[$contact->id]
                        ->setLabel($contact->full_name)
                        ->setClass('contact');
                }
            }

            if (! empty($groupTerms)) {
                $groups = (Contactgroup::on(Database::get()))
                    ->filter(Filter::equal('deleted', 'n'))
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

    /**
     * Yield recurrence rules based on the form's values
     *
     * @param int $count The number of rules to yield
     *
     * @return Generator<int, array{0: Rule, 1: DateInterval}>
     */
    private function yieldRecurrenceRules(int $count): Generator
    {
        $rule = new Rule();
        $firstRotationOffset = null;

        $options = $this->getValue('options');
        switch ($this->getValue('mode')) {
            case '24-7':
                $interval = (int) $options['interval'];
                $firstHandoff = $this->parseDateAndTime($this->getValue('first_handoff'), $options['at']);

                if ($options['frequency'] === 'd') {
                    $frequency = Frequency::DAILY;
                    $shiftDuration = new DateInterval(sprintf('P%dD', $interval));
                } else {
                    $frequency = Frequency::WEEKLY;
                    $shiftDuration = new DateInterval(sprintf('P%dW', $interval));
                }

                $rule->setFreq($frequency);
                $rule->setInterval($interval * $count);

                $ruleSeq = range(0, $count - 1);
                $rotationOffset = $shiftDuration;

                break;
            case 'partial':
                $days = array_map('intval', $options['days']);
                $interval = (int) $options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);
                $rule->setByDay(array_intersect_key(
                    [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'],
                    array_flip($days)
                ));

                $firstHandoff = $this->parseDateAndTime($this->getValue('first_handoff'), $options['from']);
                $firstHandoffDay = (int) $firstHandoff->format('N');
                if ($firstHandoffDay !== $days[0] && in_array($firstHandoffDay, $days, true)) {
                    // In case the first handoff is in the range, but doesn't start at the first day of the
                    // rotation, the first shift is shorter than regular so the first rotation offset differs
                    $firstRotationOffset = $firstHandoff->diff(
                        (clone $firstHandoff)->add(new DateInterval(sprintf(
                            'P%dD',
                            $days[0] > $firstHandoff->format('N')
                                ? $days[0] - $firstHandoff->format('N')
                                : 7 - $firstHandoff->format('N') + $days[0]
                        )))
                    );
                } elseif ($firstHandoffDay !== $days[0]) {
                    // Normalize the first handoff to the first day of the shift in case it's outside the range
                    $firstHandoff->add(new DateInterval(sprintf(
                        'P%dD',
                        $days[0] > $firstHandoffDay
                            ? $days[0] - $firstHandoffDay
                            : 7 - $firstHandoffDay + $days[0]
                    )));
                }

                $shiftEnd = $this->parseDateAndTime($firstHandoff->format('Y-m-d'), $options['to']);
                if ($firstHandoff >= $shiftEnd) {
                    $shiftEnd->add(new DateInterval('P1D'));
                }

                $rotationOffset = new DateInterval('P1W');
                $shiftDuration = $firstHandoff->diff($shiftEnd);

                $ruleSeq = [];
                for ($i = 0; $i < $count; $i++) {
                    array_push($ruleSeq, ...array_fill(0, $interval, $i));
                }

                break;
            case 'multi':
                $fromDay = (int) $options['from_day'];
                $toDay = (int) $options['to_day'];
                $interval = (int) $options['interval'];

                $rule->setFreq(Frequency::WEEKLY);
                $rule->setInterval($interval * $count);

                $ruleSeq = [];
                for ($i = 0; $i < $count; $i++) {
                    array_push($ruleSeq, ...array_fill(0, $interval, $i));
                }

                $firstHandoff = $this->parseDateAndTime($this->getValue('first_handoff'), $options['from_at']);
                $firstHandoffDay = (int) $firstHandoff->format('N');

                if (
                    $fromDay < $toDay && ($firstHandoffDay < $fromDay || $firstHandoffDay > $toDay)
                    || $toDay < $fromDay && ($firstHandoffDay < $fromDay && $firstHandoffDay > $toDay)
                ) {
                    // Normalize the first handoff to the first day of the shift in case it's outside the range
                    $firstHandoff->add(new DateInterval(sprintf(
                        'P%dD',
                        $fromDay > $firstHandoffDay
                            ? $fromDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $fromDay
                    )));
                } elseif ($firstHandoffDay !== $fromDay) {
                    // In case the first handoff is in the range, but doesn't start at the first day of the rotation,
                    // the first shift is shorter than the regular interval and separately injected into the rule seq
                    $firstRule = new Rule(null, $firstHandoff);
                    $firstRule->setUntil($firstHandoff);

                    $firstShiftEnd = (clone $firstHandoff)->add(new DateInterval(sprintf(
                        'P%dD',
                        $toDay > $firstHandoffDay
                            ? $toDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $toDay
                    )));
                    if ($this->nextHandoff !== null && $firstShiftEnd > $this->nextHandoff) {
                        $firstShiftDuration = $firstHandoff->diff($this->nextHandoff);
                    } else {
                        $firstShiftDuration = $firstHandoff->diff(
                            $this->parseDateAndTime($firstShiftEnd->format('Y-m-d'), $options['to_at'])
                        );
                    }

                    yield 0 => [$firstRule, $firstShiftDuration];

                    // The irregular first shift has been injected now, so the first regular shift needs
                    // to be pushed to the end of the rule sequence so that the pattern continues normally
                    $ruleSeq[] = array_shift($ruleSeq);

                    $firstHandoff = (clone $firstHandoff)->add(new DateInterval(sprintf(
                        'P%dD',
                        $fromDay > $firstHandoffDay
                            ? $fromDay - $firstHandoffDay
                            : 7 - $firstHandoffDay + $fromDay
                    )));
                }

                $shiftDuration = $firstHandoff->diff($this->parseDateAndTime( // returns the first end datetime
                    (clone $firstHandoff)
                        ->add(new DateInterval(sprintf(
                            'P%dD',
                            $toDay > $fromDay
                                ? $toDay - $fromDay
                                : 7 - $fromDay + $toDay
                        )))->format('Y-m-d'),
                    $options['to_at']
                ));

                $rotationOffset = new DateInterval('P1W');

                break;
            default:
                throw new LogicException('Unknown mode');
        }

        $singleOccurrences = [];
        foreach ($ruleSeq as $position) {
            $rule->setStartDate($firstHandoff);

            if ($this->nextHandoff !== null) {
                $remainingHandoffs = $this->calculateRemainingHandoffs($rule, $shiftDuration, $this->nextHandoff);

                $lastHandoff = array_shift($remainingHandoffs);
                if (! empty($remainingHandoffs)) {
                    [$gapStart, $gapEnd] = $remainingHandoffs[0];

                    $singleOccurrences[] = [$position, [
                        (new Rule(null, $gapStart))->setFreq(Frequency::YEARLY)->setUntil($gapStart),
                        $gapStart->diff($gapEnd)
                    ]];
                }

                if ($lastHandoff !== null) {
                    $rule->setUntil($lastHandoff);
                } else {
                    continue; // Skip occurrences that have no chance to happen
                }
            }

            if ($firstRotationOffset !== null) {
                $firstHandoff = (clone $firstHandoff)->add($firstRotationOffset);
                $firstRotationOffset = null;
            } else {
                $firstHandoff = (clone $firstHandoff)->add($rotationOffset);
            }

            yield $position => [$rule, $shiftDuration];
        }

        // After regular occurrences were yielded, single occurrences are yielded in the order they were generated
        foreach ($singleOccurrences as [$key, $value]) {
            yield $key => $value;
        }
    }

    /**
     * Get the last possible handoff before the given date
     *
     * @param Rule $rrule
     * @param DateInterval $shiftDuration
     * @param DateTime $before
     *
     * @return array{0: ?DateTime, 1?: array{0: DateTime, 1: DateTime}}
     */
    private function calculateRemainingHandoffs(Rule $rrule, DateInterval $shiftDuration, DateTime $before): array
    {
        if ($rrule->getStartDate() >= $before) {
            // No time passed yet, the first occurrence is in the future
            return [null];
        }

        if ($rrule->getFreq() === Frequency::YEARLY) {
            // There is only once chance that this frequency is used: For single occurrences
            $lastShiftEnd = (clone $rrule->getStartDate())->add($shiftDuration);
            if ($lastShiftEnd > $before) {
                $lastShiftEnd = clone $before;
            }

            // This relies on the fact that the calling code only knows about repeating rules, it
            // cannot update single occurrences, so $lastHandoff is null here to replace it instead
            return [null, [$rrule->getStartDate(), $lastShiftEnd]];
        } elseif ($rrule->getFreq() === Frequency::DAILY) {
            $interval = $rrule->getInterval();
        } elseif ($rrule->getFreq() === Frequency::WEEKLY) {
            $interval = $rrule->getInterval() * 7;
        } else {
            throw new LogicException('Unsupported frequency');
        }

        // $before is based on new changes, so it's required to synchronize it with the given RRULE
        $beforeNormalized = (clone $before)->setTime(
            (int) $rrule->getStartDate()->format('H'),
            (int) $rrule->getStartDate()->format('i')
        );

        $daysSinceLatestHandoff = $rrule->getStartDate()->diff($beforeNormalized)->days % $interval;
        $lastHandoff = (clone $beforeNormalized)->sub(new DateInterval(sprintf('P%dD', $daysSinceLatestHandoff)));

        $result = [];

        $byDay = $rrule->getByDay();
        if (empty($byDay)) {
            $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
            if ($lastShiftEnd > $before) {
                if ($lastHandoff < $before) {
                    // The last shift is still ongoing, so report it as the single remaining handoff
                    $result[] = [clone $lastHandoff, (clone $lastHandoff)->add($lastHandoff->diff($before))];
                }

                // Return the occurrence before the last, as it overlaps with the given date otherwise
                $lastHandoff->sub(new DateInterval(sprintf('P%dD', $interval)));
            }
        } else {
            // If this RRULE is based on a partial day configuration, forward to the very last possible shift
            $byDay = array_intersect([
                1 => 'MO',
                2 => 'TU',
                3 => 'WE',
                4 => 'TH',
                5 => 'FR',
                6 => 'SA',
                7 => 'SU'
            ], $byDay);

            $daysInTheFirstShift = max(array_keys($byDay)) - $rrule->getStartDate()->format('N');
            $lastHandoff->add(new DateInterval(sprintf('P%dD', $daysInTheFirstShift)));
            for ($i = 0; $i < $daysInTheFirstShift; $i++) {
                if (isset($byDay[$lastHandoff->format('N')]) && $lastHandoff < $before) {
                    $lastShiftEnd = (clone $lastHandoff)->add($shiftDuration);
                    if ($lastShiftEnd < $before) {
                        break;
                    } else {
                        // The last shift is still ongoing, so report it as the single remaining handoff
                        $result[] = [clone $lastHandoff, (clone $lastHandoff)->add($lastHandoff->diff($before))];
                    }
                }

                $lastHandoff->sub(new DateInterval('P1D'));
            }
        }

        if ($lastHandoff < $rrule->getStartDate()) {
            $lastHandoff = null;
        }

        array_unshift($result, $lastHandoff);

        return $result;
    }
}
