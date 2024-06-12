<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Rotation;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use LogicException;

class MoveRotationForm extends Form
{
    use CsrfCounterMeasure;

    protected $defaultAttributes = ['hidden' => true];

    protected $method = 'POST';

    /** @var Connection */
    protected $db;

    /** @var int */
    protected $scheduleId;

    /**
     * Create a new MoveRotationForm
     *
     * @param ?Connection $db
     */
    public function __construct(Connection $db = null)
    {
        $this->db = $db;
    }

    /**
     * Get the schedule ID
     *
     * @return int
     */
    public function getScheduleId(): int
    {
        if ($this->scheduleId === null) {
            throw new LogicException('The form must be successfully submitted first');
        }

        return $this->scheduleId;
    }

    public function getMessages()
    {
        $messages = parent::getMessages();
        foreach ($this->getElements() as $element) {
            foreach ($element->getMessages() as $message) {
                $messages[] = sprintf('%s: %s', $element->getName(), $message);
            }
        }

        return $messages;
    }

    protected function assemble()
    {
        $this->addElement('hidden', 'rotation', ['required' => true]);
        $this->addElement('hidden', 'priority', ['required' => true]);
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
    }

    protected function onError()
    {
        $this->removeAttribute('hidden');

        parent::onError();
    }

    protected function onSuccess()
    {
        $rotationId = $this->getValue('rotation');
        $newPriority = $this->getValue('priority');

        /** @var ?Rotation $rotation */
        $rotation = Rotation::on($this->db)
            ->columns(['schedule_id', 'priority'])
            ->filter(Filter::equal('id', $rotationId))
            ->first();
        if ($rotation === null) {
            throw new HttpNotFoundException('Rotation not found');
        }

        $transactionStarted = ! $this->db->inTransaction();
        if ($transactionStarted) {
            $this->db->beginTransaction();
        }

        $this->scheduleId = $rotation->schedule_id;

        // Free up the current priority used by the rotation in question
        $this->db->update('rotation', ['priority' => 9999], ['id = ?' => $rotationId]);

        // Update the priorities of the rotations that are affected by the move
        if ($newPriority < $rotation->priority) {
            $affectedRotations = $this->db->select(
                (new Select())
                    ->columns('id')
                    ->from('rotation')
                    ->where([
                        'schedule_id = ?' => $rotation->schedule_id,
                        'priority >= ?' => $newPriority,
                        'priority < ?' => $rotation->priority
                    ])
                    ->orderBy('priority DESC')
            );
            foreach ($affectedRotations as $rotation) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority + 1')],
                    ['id = ?' => $rotation->id]
                );
            }
        } elseif ($newPriority > $rotation->priority) {
            $affectedRotations = $this->db->select(
                (new Select())
                    ->columns('id')
                    ->from('rotation')
                    ->where([
                        'schedule_id = ?' => $rotation->schedule_id,
                        'priority > ?' => $rotation->priority,
                        'priority <= ?' => $newPriority
                    ])
                    ->orderBy('priority ASC')
            );
            foreach ($affectedRotations as $rotation) {
                $this->db->update(
                    'rotation',
                    ['priority' => new Expression('priority - 1')],
                    ['id = ?' => $rotation->id]
                );
            }
        }

        // Now insert the rotation at the new priority
        $this->db->update('rotation', ['priority' => $newPriority], ['id = ?' => $rotationId]);

        if ($transactionStarted) {
            $this->db->commitTransaction();
        }
    }
}
