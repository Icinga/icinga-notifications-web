<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Form\Data\Rotation;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class ContactGroupRepository
{
    /**
    * Create a `ContactGroupRepository` instance
    *
    * @param Connection $db Database to operate on
    */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the contact group with the given ID
     *
     * @param int $id
     *
     * @return ?Contactgroup
     */
    public function find(int $id): ?Contactgroup
    {
        return Contactgroup::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();
    }

    /**
     * Store a new contact group
     *
     * @param ContactGroupData $group
     *
     * @return int The ID of the given contact group
     */
    public function create(ContactGroupData $group): int
    {
        $model = (new Contactgroup())->setNew();
        $model->name = $group->name;
        $model->external_uuid = Uuid::uuid4()->toString();
        $model->contact = Collection::create(
            Contact::class,
            array_map(fn($id) => new Contact(['id' => $id]), $group->members)
        );

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given contact group
     *
     * @param ContactGroupData $group
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given group does not exist yet
     */
    public function update(ContactGroupData $group): void
    {
        if (! isset($group->id)) {
            throw new InvalidArgumentException('Cannot update a contact group that does not have an ID');
        }

        $model = $this->find($group->id)?->setNew(false);
        if ($model === null) {
            throw new RuntimeException('Cannot update a contact group that does not exist in the database');
        }

        $model->name = $group->name;
        $model->contact = array_map(fn($id) => new Contact(['id' => $id]), $group->members);

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Delete the given contact group
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $model = $this->find($id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot delete a contact group that does not exist in the database');
        }

        $model->contact = [];
        $model->delete();

        $model->rotation
            ->query()
            ->withColumns(['schedule.timezone'])
            ->orderBy('priority', SORT_DESC); // Important, MUST BE DESC to not open gaps when deleting one below
        $model->rule_escalation->query()->columns('id');

        foreach ($model->rotation as $rotation) {
            $rotation->member
                ->query()
                ->columns(['id', 'contact_id', 'contactgroup_id', 'position']);

            $otherMembers = [];
            foreach ($rotation->member as $member) {
                if ($member->contactgroup_id === $id) {
                    $member->position = null;
                    $member->delete();
                } else {
                    $otherMembers[] = [
                        match (true) {
                            isset($member->contact_id) => 'contact',
                            isset($member->contactgroup_id) => 'contact_group'
                        },
                        $member->contact_id ?? $member->contactgroup_id
                    ];
                }
            }

            if (empty($otherMembers)) {
                (new RotationRepository($this->db))->delete($rotation->id);
            } else {
                (new RotationRepository($this->db))->update(new Rotation(
                    id: $rotation->id,
                    scheduleId: $rotation->schedule_id,
                    priority: $rotation->priority,
                    name: $rotation->name,
                    mode: $rotation->mode,
                    options: $rotation->options,
                    members: $otherMembers,
                    firstHandoff: DateTimeImmutable::createFromFormat(
                        'Y-m-d',
                        $rotation->first_handoff,
                        new DateTimeZone($rotation->schedule->timezone)
                    )
                ));
            }
        }

        foreach ($model->rule_escalation as $escalation) {
            $model->rule_escalation->detach($escalation);

            $otherRecipient = $escalation->rule_escalation_recipient
                ->query()
                ->columns([new Expression('1')])
                ->filter(Filter::unequal('contactgroup_id', $id))
                ->first();
            if ($otherRecipient === null) {
                (new EscalationRepository($this->db))->delete($escalation->id);
            }
        }

        (new EntityManager($this->db))->save($model);
    }
}
