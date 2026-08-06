<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Contact as ContactData;
use Icinga\Module\Notifications\Form\Data\Rotation;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class ContactRepository
{
    /**
     * Create a `ContactRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the contact with the given ID
     *
     * @param int $id
     *
     * @return ?Contact
     */
    public function find(int $id): ?Contact
    {
        return Contact::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();
    }

    /**
     * Fetch the contact with the given username
     *
     * @param string $username
     *
     * @return ?Contact
     */
    public function findByUsername(string $username): ?Contact
    {
        return Contact::on($this->db)
            ->filter(Filter::equal('username', $username))
            ->first();
    }

    /**
     * Store a new contact
     *
     * The given contact is assigned an ID after successful creation.
     *
     * @param ContactData $contact
     *
     * @return int The ID of the given contact
     */
    public function create(ContactData $contact): int
    {
        $model = (new Contact())->setNew();
        $model->full_name = $contact->fullName;
        $model->username = $contact->username;
        $model->default_channel_id = $contact->channelId;
        $model->external_uuid = Uuid::uuid4()->toString();

        $addresses = [];
        foreach ($contact->addresses as $type => $address) {
            $addresses[] = (new ContactAddress([
                'type' => $type,
                'address' => $address
            ]))->setNew();
        }

        $model->contact_address = Collection::create(ContactAddress::class, $addresses);

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }

    /**
     * Update the given contact and perform a differential update on the associated addresses
     *
     * @param ContactData $contact
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given contact does not have an ID
     */
    public function update(ContactData $contact): void
    {
        if (! isset($contact->id)) {
            throw new InvalidArgumentException('Cannot update a contact that does not have an ID');
        }

        $model = $this->find($contact->id)?->setNew(false);
        if ($model === null) {
            // Can happen in case of parallel changes.
            // Thought about calling create() instead to simulate an upsert,
            // but dismissed it since the object has an ID.
            throw new RuntimeException('Cannot update a contact that does not exist in the database');
        }

        $model->full_name = $contact->fullName;
        $model->username = $contact->username;
        $model->default_channel_id = $contact->channelId;

        $requiredAddresses = $contact->addresses;
        foreach ($model->contact_address as $contactAddress) {
            if (isset($requiredAddresses[$contactAddress->type])) {
                $contactAddress->address = $requiredAddresses[$contactAddress->type];
                unset($requiredAddresses[$contactAddress->type]);
            } else {
                $model->contact_address->detach($contactAddress);
            }
        }

        foreach ($requiredAddresses as $type => $address) {
            $model->contact_address->attach((new ContactAddress([
                'type' => $type,
                'address' => $address
            ]))->setNew());
        }

        (new EntityManager($this->db))->save($model);
    }

    /**
     * Delete the contact with the given id and all associations
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $model = $this->find($id)?->setNew(false);
        if ($model === null) {
            // Right now I think throwing is the safest bet as the alternative is too demanding to think about…
            throw new RuntimeException('Cannot delete a contact that does not exist in the database');
        }

        $model->contact_address = [];
        $model->contactgroup = []; // TODO: Unsure whether this is it or whether the manager should implicitly clean up
        $model->external_uuid = null;
        $model->username = null;
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
                if ($member->contact_id === $id) {
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
                ->filter(Filter::unequal('contact_id', $id))
                ->first();
            if ($otherRecipient === null) {
                (new EscalationRepository($this->db))->delete($escalation->id);
            }
        }

        (new EntityManager($this->db))->save($model);
    }
}
