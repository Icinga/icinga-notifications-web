<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use DateTimeZone;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class ScheduleForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var ?string */
    protected ?string $submitLabel;

    /** @var bool */
    protected bool $showRemoveButton = false;

    /** @var bool */
    protected bool $showTimezoneDropdown = false;

    /** @var Connection */
    private Connection $db;

    /** @var ?int */
    private ?int $scheduleId = null;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->applyDefaultElementDecorators();
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

    /**
     * Set whether to show the timezone dropdown or not
     *
     * @param bool $state If true, the timezone dropdown will be shown (defaults to true)
     *
     * @return $this
     */
    public function setShowTimezoneDropdown(bool $state = true): self
    {
        $this->showTimezoneDropdown = $state;

        return $this;
    }

    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    public function loadSchedule(int $id): void
    {
        $this->scheduleId = $id;
        $this->populate($this->fetchDbValues());
    }

    public function addSchedule(): int
    {
        return $this->db->transaction(function (Connection $db) {
            $db->insert('schedule', [
                'name'       => $this->getValue('name'),
                'changed_at' => (int) (new DateTime())->format("Uv"),
                'timezone'   => $this->getValue('timezone')
            ]);

            return $db->lastInsertId();
        });
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
            'changed_at'    => (int) (new DateTime())->format("Uv")
        ], ['id = ?' => $id]);

        $this->db->commitTransaction();
    }

    public function removeSchedule(int $id): void
    {
        $this->db->beginTransaction();

        $rotations = Rotation::on($this->db)
            ->columns(['id', 'schedule_id', 'priority', 'timeperiod.id'])
            ->filter(Filter::equal('schedule_id', $id))
            ->orderBy('priority', SORT_DESC);

        /** @var Rotation $rotation */
        foreach ($rotations as $rotation) {
            $rotation->delete();
        }

        $markAsDeleted = ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'];

        $escalationIds = $this->db->fetchCol(
            RuleEscalationRecipient::on($this->db)
                ->columns('rule_escalation_id')
                ->filter(Filter::equal('schedule_id', $id))
                ->assembleSelect()
        );

        $this->db->update('rule_escalation_recipient', $markAsDeleted, ['schedule_id = ?' => $id]);

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->db->fetchCol(
                RuleEscalationRecipient::on($this->db)
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::unequal('schedule_id', $id)
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $this->db->update(
                    'rule_escalation',
                    $markAsDeleted + ['position' => null],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $this->db->update('schedule', $markAsDeleted, ['id = ?' => $id]);

        $this->db->commitTransaction();
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

        $this->addElement('text', 'name', [
            'required'      => true,
            'label'         => $this->translate('Schedule Name'),
            'placeholder'   => $this->translate('e.g. working hours, on call, etc ...')
        ]);

        if ($this->showTimezoneDropdown) {
            $this->addElement('select', 'timezone', [
                'required'     => true,
                'label'        => $this->translate('Schedule Timezone'),
                'description'  => $this->translate('Select the time zone in which this schedule operates.'),
                'multiOptions' => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()),
                'value'        => date_default_timezone_get(),
            ]);
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
