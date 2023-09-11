<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\ScheduleMember;
use Icinga\Module\Notifications\Model\TimeperiodEntry;
use Icinga\Web\Session;
use ipl\Html\HtmlDocument;
use ipl\Orm\Behavior\Binary;
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

class EntryForm extends CompatForm
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
        return $this->submitLabel ?? $this->translate('Add Entry');
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

    public function loadEntry(int $scheduleId, int $id): self
    {
        $entry = TimeperiodEntry::on(Database::get())
            ->filter(Filter::equal('id', $id))
            ->first();
        if ($entry === null) {
            throw new HttpNotFoundException($this->translate('Entry not found'));
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
            if (! isset($values['membership_hash'])) {
                $values['membership_hash'] = (new Binary([]))->toDb($member->membership_hash, null, null);
            }

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
            /** @var EntryForm */
            private $parent;

            protected function init(): void
            {
                parent::init();

                unset($this->advanced[self::CRON_EXPR]);
                unset($this->regulars[RRule::MINUTELY]);
            }

            public function setParent(EntryForm $parent): self
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
                    ->setDescription(null)
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
                    ->setDescription(null)
                    ->setLabel($this->translate('Repeat'))
                    ->getOption(self::NO_REPEAT)
                        ->setLabel($this->translate('Never'));

                $useEndTime = $this->getElement('use-end-time');
                $useEndTime->setLabel($this->translate('Use Until Time'));

                if ($useEndTime->isChecked()) {
                    $this->getElement('end')
                        ->setDescription(null)
                        ->setLabel($this->translate('Repeat Until'))
                        ->addValidators([new CallbackValidator(function ($value, $validator) {
                            $startTime = $this->getValue('start');
                            if ($value < $startTime) {
                                $validator->addMessage(
                                    $this->translate('The entry must occur at least once.')
                                );

                                return false;
                            }

                            return true;
                        })]);
                }
            }
        };

        $this->addElement('hidden', 'timeperiod_id');
        $this->addElement('hidden', 'membership_hash');

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

    public function addEntry(int $scheduleId): void
    {
        $data = $this->formValuesToDb();
        $membershipHash = $this->getMembershipHashFromRecipients($scheduleId);
        $recipients = array_map(function ($recipient) {
            return explode(':', $recipient, 2);
        }, explode(',', $this->getValue('recipient')));

        $db = Database::get();
        $db->beginTransaction();

        $members = ScheduleMember::on(Database::get())
            ->with('timeperiod')
            ->filter(
                Filter::all(
                    Filter::equal('timeperiod.owned_by_schedule_id', $scheduleId),
                    Filter::equal('schedule_member.membership_hash', $membershipHash)
                )
            );

        if ($members->count() > 0) {
            $timeperiodId = $members->first()->timeperiod_id;
        } else {
            $db->insert('timeperiod', ['owned_by_schedule_id' => $scheduleId]);
            $timeperiodId = $db->lastInsertId();
        }

        $db->insert('timeperiod_entry', $data + ['timeperiod_id' => $timeperiodId]);

        if ($members->count() === 0) {
            $binaryMemberHash = (new Binary([]))->toDb($membershipHash, null, null);
            foreach ($recipients as list($type, $id)) {
                if ($type === 'contact') {
                    $db->insert('schedule_member', [
                        'schedule_id'     => $scheduleId,
                        'timeperiod_id'   => $timeperiodId,
                        'contact_id'      => $id,
                        'membership_hash' => $binaryMemberHash
                    ]);
                } elseif ($type === 'group') {
                    $db->insert('schedule_member', [
                        'schedule_id'     => $scheduleId,
                        'timeperiod_id'   => $timeperiodId,
                        'contactgroup_id' => $id,
                        'membership_hash' => $binaryMemberHash
                    ]);
                }
            }
        }

        $db->commitTransaction();
    }

    /**
     * Get membership hash for the recipients in timeperiod entry for the given schedule
     *
     * @param int $scheduleID
     *
     * @return string
     */
    public function getMembershipHashFromRecipients(int $scheduleID): string
    {
        $recipients = explode(',', $this->getValue('recipient'));
        sort($recipients);
        return sha1(sprintf('schedule:%d,%s', $scheduleID, implode(',', $recipients)), true);
    }

    public function editEntry(int $scheduleId, int $id): void
    {
        $data = $this->formValuesToDb();
        $db = Database::get();
        $binaryBehavior = new Binary([]);

        $prevTimeperiodId = $this->getValue('timeperiod_id');
        $suppliedHash = $this->getValue('membership_hash');
        $calculatedHash = $this->getMembershipHashFromRecipients($scheduleId);
        $changedMembers = ScheduleMember::on(Database::get())
            ->with('timeperiod')
            ->filter(
                Filter::all(
                    Filter::equal('timeperiod.owned_by_schedule_id', $scheduleId),
                    Filter::equal('schedule_member.membership_hash', $calculatedHash)
                )
            );

        $prevTimeperiodEntriesCount = TimeperiodEntry::on($db)
            ->with('timeperiod_entry.timeperiod')
            ->filter(Filter::equal('timeperiod.owned_by_schedule_id', $scheduleId))
            ->filter(Filter::equal('timeperiod_id', $prevTimeperiodId))
            ->count();

        $db->beginTransaction();

        if ($changedMembers->count() > 0) {
            // Update the entries with timeperiod_id of the changed membership
            $db->update(
                'timeperiod_entry',
                $data + ['timeperiod_id' => (int) $changedMembers->first()->timeperiod_id],
                ['id = ?' => $id]
            );
            $prevTimeperiodEntriesCount -= 1;
        } elseif ($prevTimeperiodEntriesCount === 1) {
            // Update the membership hash and add or remove members for the combination of existing
            // schedule_id and timeperiod_id
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
            $prevMembers = ScheduleMember::on(Database::get())
                ->with('timeperiod')
                ->filter(
                    Filter::all(
                        Filter::equal('timeperiod.owned_by_schedule_id', $scheduleId),
                        Filter::equal('schedule_member.membership_hash', $suppliedHash)
                    )
                );

            foreach ($prevMembers as $member) {
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
                    'membership_hash = ?' => $suppliedHash,
                    'contact_id IN (?)' => $usersToRemove
                ]);
            }

            if (! empty($groupsToRemove)) {
                $db->delete('schedule_member', [
                    'membership_hash = ?' => $suppliedHash,
                    'contactgroup_id IN (?)' => $groupsToRemove
                ]);
            }

            $binaryMemberHash = $binaryBehavior->toDb($calculatedHash, null, null);

            $db->update(
                'schedule_member',
                ['membership_hash' => $binaryMemberHash],
                ['membership_hash = ?' => $suppliedHash]
            );

            foreach ($users as $user) {
                $db->insert('schedule_member', [
                    'schedule_id' => $scheduleId,
                    'timeperiod_id' => $prevTimeperiodId,
                    'contact_id' => $user,
                    'membership_hash' => $binaryMemberHash
                ]);
            }

            foreach ($groups as $group) {
                $db->insert('schedule_member', [
                    'schedule_id' => $scheduleId,
                    'timeperiod_id' => $prevTimeperiodId,
                    'contactgroup_id' => $group,
                    'membership_hash' => $binaryMemberHash
                ]);
            }
        } else {
            // Create new timeperiod and new members for the newly generated hash and update timeperiod entries
            $db->insert('timeperiod', ['owned_by_schedule_id' => $scheduleId]);
            $timeperiodId = $db->lastInsertId();

            $db->update(
                'timeperiod_entry',
                $data + ['timeperiod_id' => $timeperiodId],
                ['id = ?' => $id]
            );

            $prevTimeperiodEntriesCount -= 1;
            if ($changedMembers->count() === 0) {
                $recipients = explode(',', $this->getValue('recipient'));

                $binaryMemberHash = $binaryBehavior->toDb($calculatedHash, null, null);
                foreach ($recipients as $recipient) {
                    list($type, $recipientId) = explode(':', $recipient, 2);
                    if ($type === 'contact') {
                        $db->insert('schedule_member', [
                            'schedule_id'     => $scheduleId,
                            'timeperiod_id'   => $timeperiodId,
                            'contact_id'      => $recipientId,
                            'membership_hash' => $binaryMemberHash
                        ]);
                    } elseif ($type === 'group') {
                        $db->insert('schedule_member', [
                            'schedule_id'     => $scheduleId,
                            'timeperiod_id'   => $timeperiodId,
                            'contactgroup_id' => $recipientId,
                            'membership_hash' => $binaryMemberHash
                        ]);
                    }
                }
            }
        }

        if ($prevTimeperiodEntriesCount === 0) {
            $db->delete(
                'schedule_member',
                ['membership_hash = ?' => $suppliedHash]
            );

            $db->delete('timeperiod', [
                'id = ?' => $prevTimeperiodId,
                'owned_by_schedule_id = ?' => $scheduleId
            ]);
        }

        $db->commitTransaction();
    }

    public function removeEntry(int $scheduleId, int $id): void
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
