<?php

namespace Icinga\Module\Noma\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Contact;
use Icinga\Module\Noma\Model\Contactgroup;
use Icinga\Module\Noma\Model\ScheduleMember;
use Icinga\Module\Noma\Model\TimeperiodEntry;
use Icinga\Web\Session;
use ipl\Html\HtmlDocument;
use ipl\Scheduler\Contract\Frequency;
use ipl\Scheduler\OneOff;
use ipl\Scheduler\RRule;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\ScheduleElement;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Url;

class EventForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string */
    protected $submitLabel;

    /** @var bool */
    protected $showRemoveButton = false;

    /** @var Url */
    protected $suggestionUrl;

    public function setSubmitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? $this->translate('Create Event');
    }

    public function setShowRemoveButton(bool $state = true): self
    {
        $this->showRemoveButton = $state;

        return $this;
    }

    public function setSuggestionUrl(Url $url): self
    {
        $this->suggestionUrl = $url;

        return $this;
    }

    public function getPartUpdates(): array
    {
        $this->ensureAssembled();

        return array_merge(
            $this->getElement('when')->prepareMultipartUpdate($this->getRequest()),
            $this->getElement('recipient')->prepareMultipartUpdate($this->getRequest())
        );
    }

    public function hasBeenCancelled(): bool
    {
        $btn = $this->getPressedSubmitElement();

        return $btn !== null && $btn->getName() === 'cancel';
    }

    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'remove';
    }

    public function loadEvent(int $scheduleId, int $id): self
    {
        $entry = TimeperiodEntry::on(Database::get())
            ->filter(Filter::equal('id', $id))
            ->first();
        if ($entry === null) {
            throw new HttpNotFoundException($this->translate('Event not found'));
        }

        $values = [
            'timeperiod_id' => $entry->timeperiod_id,
            'end_at' => $entry->end_time,
            'description' => $entry->description
        ];

        if (isset($entry->frequency)) {
            $values['when'] = RRule::fromJson(json_encode([
                'frequency' => $entry->frequency,
                'rrule' => $entry->rrule,
                'start' => $entry->start_time->format(Frequency::SERIALIZED_DATETIME_FORMAT)
            ]));
        } else {
            $values['when'] = OneOff::fromJson(
                '"' . $entry->start_time->format(Frequency::SERIALIZED_DATETIME_FORMAT) . '"'
            );
        }

        $members = ScheduleMember::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('schedule_id', $scheduleId),
                Filter::equal('timeperiod_id', $entry->timeperiod_id)
            ));

        $recipients = [];
        foreach ($members as $member) {
            if ($member->contact_id !== null) {
                $recipients[] = 'contact:' . $member->contact_id;
            } else {
                $recipients[] = 'group:' . $member->contactgroup_id;
            }
        }

        $values['recipient'] = implode(',', $recipients);

        $this->populate($values);

        return $this;
    }

    protected function assemble()
    {
        $scheduleElement = new class ('when') extends ScheduleElement {
            /** @var EventForm */
            private $parent;

            protected function init(): void
            {
                parent::init();

                unset($this->advanced[self::CRON_EXPR]);
                unset($this->regulars[RRule::MINUTELY]);
            }

            public function setParent(EventForm $parent): self
            {
                $this->parent = $parent;

                return $this;
            }

            protected function assemble()
            {
                parent::assemble();

                $end = $this->createElement('localDateTime', 'end_at', [
                    'required' => true,
                    'label' => $this->translate('End')
                ]);
                $this->decorate($end);
                $this->parent->registerElement($end);

                $this->getElement('start')
                    ->addValidators([new CallbackValidator(function ($value, $validator) {
                        $endTime = $this->parent->getValue('end_at');
                        if ($value >= $endTime) {
                            $validator->addMessage(
                                $this->translate('The start date must not be later than the end.')
                            );

                            return false;
                        }

                        return true;
                    })])->addWrapper(
                        (new HtmlDocument())
                            ->setHtmlContent(
                                $this->getElement('start')->getWrapper(),
                                $end
                            )
                    );

                $this->getElement('frequency')
                    ->setLabel($this->translate('Repeat'))
                    ->getOption(self::NO_REPEAT)
                        ->setLabel($this->translate('Never'));

                if ($this->getElement('use-end-time')->isChecked()) {
                    $this->getElement('end')
                        ->setLabel($this->translate('Repeat Until'));
                }
            }
        };

        $this->addElement('hidden', 'timeperiod_id');

        $this->addElement('textarea', 'description', [
            'label' => $this->translate('Description'),
            'rows' => 8,
            'class' => 'autofocus'
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

        $termInput = (new TermInput('recipient'))
            ->setRequired()
            ->setLabel(t('Recipients'))
            ->setSuggestionUrl($this->suggestionUrl->with(['showCompact' => true, '_disableLayout' => 1]))
            ->on(TermInput::ON_ENRICH, $termValidator)
            ->on(TermInput::ON_ADD, $termValidator)
            ->on(TermInput::ON_SAVE, $termValidator)
            ->on(TermInput::ON_PASTE, $termValidator);

        $this->addElement($termInput);

        $this->addElement($scheduleElement->setParent($this));

        $this->addElement('submit', 'submit', [
            'label' => $this->getSubmitLabel()
        ]);

        $additionalButtons = [];
        $cancelBtn = $this->createElement('submit', 'cancel', [
            'label' => $this->translate('Cancel'),
            'class' => 'btn-cancel',
            'formnovalidate' => true
        ]);
        $this->registerElement($cancelBtn);
        $additionalButtons[] = $cancelBtn;

        if ($this->showRemoveButton) {
            $removeBtn = $this->createElement('submit', 'remove', [
                'label' => $this->translate('Remove'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);
            $additionalButtons[] = $removeBtn;
        }

        $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent(...$additionalButtons));

        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
    }

    public function addEvent(int $scheduleId): void
    {
        $data = $this->formValuesToDb();
        $recipients = array_map(function ($recipient) {
            return explode(':', $recipient, 2);
        }, explode(',', $this->getValue('recipient')));

        $db = Database::get();
        $db->beginTransaction();

        $db->insert('timeperiod', ['owned_by_schedule_id' => $scheduleId]);
        $timeperiodId = $db->lastInsertId();

        $db->insert('timeperiod_entry', $data + ['timeperiod_id' => $timeperiodId]);

        foreach ($recipients as list($type, $id)) {
            if ($type === 'contact') {
                $db->insert('schedule_member', [
                    'schedule_id' => $scheduleId,
                    'timeperiod_id' => $timeperiodId,
                    'contact_id' => $id
                ]);
            } elseif ($type === 'group') {
                $db->insert('schedule_member', [
                    'schedule_id' => $scheduleId,
                    'timeperiod_id' => $timeperiodId,
                    'contactgroup_id' => $id
                ]);
            }
        }

        $db->commitTransaction();
    }

    public function editEvent(int $scheduleId, int $id): void
    {
        $data = $this->formValuesToDb();
        $timeperiodId = (int) $this->getValue('timeperiod_id');

        $db = Database::get();
        $db->beginTransaction();

        $db->update('timeperiod_entry', $data, ['id = ?' => $id]);

        $members = ScheduleMember::on($db)
            ->filter(Filter::all(
                Filter::equal('schedule_id', $scheduleId),
                Filter::equal('timeperiod_id', $timeperiodId)
            ));

        $recipients = explode(',', $this->getValue('recipient'));

        $users = [];
        $groups = [];
        foreach ($recipients as $recipient) {
            list($type, $id) = explode(':', $recipient, 2);

            if ($type === 'contact') {
                $users[$id] = $id;
            } elseif ($type === 'group') {
                $groups[$id] = $id;
            }
        }

        $usersToRemove = [];
        $groupsToRemove = [];
        foreach ($members as $member) {
            if ($member->contact_id !== null) {
                if (! isset($users[$member->contact_id])) {
                    $usersToRemove[] = $member->contact_id;
                } else {
                    unset($users[$member->contact_id]);
                }
            } else {
                if (! isset($groups[$member->contactgroup_id])) {
                    $groupsToRemove[] = $member->contactgroup_id;
                } else {
                    unset($groups[$member->contactgroup_id]);
                }
            }
        }

        if (! empty($usersToRemove)) {
            $db->delete('schedule_member', [
                'schedule_id = ?' => $scheduleId,
                'timeperiod_id = ?' => $timeperiodId,
                'contact_id IN (?)' => $usersToRemove
            ]);
        }

        if (! empty($groupsToRemove)) {
            $db->delete('schedule_member', [
                'schedule_id = ?' => $scheduleId,
                'timeperiod_id = ?' => $timeperiodId,
                'contactgroup_id IN (?)' => $groupsToRemove
            ]);
        }

        foreach ($users as $user) {
            $db->insert('schedule_member', [
                'schedule_id' => $scheduleId,
                'timeperiod_id' => $timeperiodId,
                'contact_id' => $user
            ]);
        }

        foreach ($groups as $group) {
            $db->insert('schedule_member', [
                'schedule_id' => $scheduleId,
                'timeperiod_id' => $timeperiodId,
                'contactgroup_id' => $group
            ]);
        }

        $db->commitTransaction();
    }

    public function removeEvent(int $scheduleId, int $id): void
    {
        $timeperiodId = (int) $this->getValue('timeperiod_id');

        $db = Database::get();
        $db->beginTransaction();

        $db->delete('timeperiod_entry', ['id = ?' => $id]);
        $db->delete('schedule_member', ['timeperiod_id = ?' => $timeperiodId]);
        $db->delete('timeperiod', [
            'id = ?' => $timeperiodId,
            'owned_by_schedule_id = ?' => $scheduleId
        ]);

        $db->commitTransaction();
    }

    protected function formValuesToDb(): array
    {
        /** @var Frequency $when */
        $when = $this->getValue('when');

        // The final start may get synchronized with the recurrency rule, end_at is not and cannot be used directly
        $duration = $this->getElement('when')->getValue('start')
            ->diff($this->getValue('end_at'));

        $until = null;
        $frequency = null;
        $serializedFrequency = $when->jsonSerialize();
        if ($when instanceof RRule) {
            if (($untilTime = $when->getUntil()) !== null) {
                $until = $untilTime->format('U.u') * 1000.0;
            }

            $rrule = $serializedFrequency['rrule'];
            $frequency = $serializedFrequency['frequency'];
            $start = DateTime::createFromFormat(
                Frequency::SERIALIZED_DATETIME_FORMAT,
                $serializedFrequency['start']
            );
        } else {
            /** @var OneOff $when */
            $rrule = null;
            $start = DateTime::createFromFormat(
                Frequency::SERIALIZED_DATETIME_FORMAT,
                $serializedFrequency
            );
        }

        return [
            'start_time' => $start->format('U.u') * 1000.0,
            'end_time' => (clone $start)->add($duration)->format('U.u') * 1000.0,
            'timezone' => $start->getTimezone()->getName(),
            'rrule' => $rrule,
            'until_time' => $until,
            'frequency' => $frequency,
            'description' => $this->getValue('description')
        ];
    }
}
