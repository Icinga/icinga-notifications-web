<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Web\Session;
use ipl\Html\HtmlDocument;
use ipl\Sql\Connection;
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

    /** @var Connection */
    private $db;

    /** @var ?int */
    private $scheduleId;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

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
        $this->scheduleId = $id;
        $this->populate($this->fetchDbValues());
    }

    public function addSchedule(): int
    {
        $this->db->insert('schedule', [
            'name' => $this->getValue('name')
        ]);

        return $this->db->lastInsertId();
    }

    public function editSchedule(int $id): void
    {
        $this->db->beginTransaction();

        $values = $this->getValues();
        $storedValues = $this->fetchDbValues();

        if ($values === $storedValues) {
            return;
        }

        $this->db->update('schedule', [
            'name'          => $values['name'],
            'changed_at'    => time() * 1000
        ], ['id = ?' => $id]);

        $this->db->commitTransaction();
    }

    public function removeSchedule(int $id): void
    {
        $this->db->beginTransaction();

        $rotations = Rotation::on($this->db)
            ->columns('priority')
            ->filter(Filter::equal('schedule_id', $id))
            ->orderBy('priority', SORT_DESC);

        $rotationConfigForm = new RotationConfigForm($id, $this->db);

        foreach ($rotations as $rotation) {
            $rotationConfigForm->wipeRotation($rotation->priority);
        }

        $markAsDeleted = ['changed_at' => time() * 1000, 'deleted' => 'y'];

        $this->db->update('rule_escalation_recipient', $markAsDeleted, ['schedule_id = ?' => $id]);
        $this->db->update('schedule', $markAsDeleted, ['id = ?' => $id]);

        $this->db->commitTransaction();
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

    /**
     * Fetch the values from the database
     *
     * @return string[]
     *
     * @throws HttpNotFoundException
     */
    private function fetchDbValues(): array
    {
        /** @var ?Schedule $schedule */
        $schedule = Schedule::on($this->db)
            ->columns('name')
            ->filter(Filter::equal('id', $this->scheduleId))
            ->first();

        if ($schedule === null) {
            throw new HttpNotFoundException($this->translate('Schedule not found'));
        }

        return ['name' => $schedule->name];
    }
}
