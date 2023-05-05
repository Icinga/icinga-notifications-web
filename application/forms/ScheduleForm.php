<?php

namespace Icinga\Module\Noma\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Schedule;
use Icinga\Module\Noma\Model\Timeperiod;
use Icinga\Web\Session;
use ipl\Html\HtmlDocument;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class ScheduleForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string */
    protected $submitLabel;

    /** @var bool */
    protected $showRemoveButton = false;

    public function setSubmitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? $this->translate('Create Schedule');
    }

    public function setShowRemoveButton(bool $state = true): self
    {
        $this->showRemoveButton = $state;

        return $this;
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

    public function loadSchedule(int $id): void
    {
        $db = Database::get();

        $schedule = Schedule::on($db)
            ->filter(Filter::equal('id', $id))
            ->first();
        if ($schedule === null) {
            throw new HttpNotFoundException($this->translate('Schedule not found'));
        }

        $this->populate(['name' => $schedule->name]);
    }

    public function addSchedule(): int
    {
        $db = Database::get();

        $db->insert('schedule', [
            'name' => $this->getValue('name')
        ]);

        return $db->lastInsertId();
    }

    public function editSchedule(int $id): void
    {
        $db = Database::get();

        $db->update('schedule', [
            'name' => $this->getValue('name')
        ], ['id = ?' => $id]);
    }

    public function removeSchedule(int $id): void
    {
        $db = Database::get();
        $db->beginTransaction();

        $timeperiods = Timeperiod::on($db)
            ->filter(Filter::equal('owned_by_schedule_id', $id));
        foreach ($timeperiods as $timeperiod) {
            $db->delete('timeperiod_entry', ['timeperiod_id = ?' => $timeperiod->id]);
            $db->delete('schedule_member', ['timeperiod_id = ?' => $timeperiod->id]);
            $db->delete('timeperiod', ['id = ?' => $timeperiod->id]);
        }

        $db->delete('schedule', ['id = ?' => $id]);

        $db->commitTransaction();
    }

    protected function assemble()
    {
        $this->addElement('text', 'name', [
            'required' => true,
            'label' => $this->translate('Name')
        ]);

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
}
