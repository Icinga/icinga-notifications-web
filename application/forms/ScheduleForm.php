<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Model\Timeperiod;
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

        $rotations = Rotation::on($db)
            ->columns('priority')
            ->filter(Filter::equal('schedule_id', $id))
            ->orderBy('priority', SORT_DESC);

        $rotationConfigForm = new RotationConfigForm($id, $db);

        foreach ($rotations as $rotation) {
            $rotationConfigForm->wipeRotation($rotation->priority);
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

        if ($this->showRemoveButton) {
            $removeBtn = $this->createElement('submit', 'remove', [
                'label' => $this->translate('Remove'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);

            $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent($removeBtn));
        }

        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
    }
}
