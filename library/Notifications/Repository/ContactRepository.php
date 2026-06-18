<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;

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
        /** @var ?Contact $contact */
        $contact = Contact::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();

        return $contact;
    }

    /**
     * Store a new contact
     *
     * The given contact is assigned an ID after successful creation.
     *
     * @param Contact $contact
     *
     * @return void
     */
    public function create(Contact $contact): void
    {
        $changedAt = (int) (new DateTime())->format("Uv");

        $this->db->insert(
            'contact',
            [
                'external_uuid' => Uuid::uuid4()->toString(),
                'full_name' => $contact->full_name,
                'username' => $contact->username,
                'default_channel_id' => null,
                'changed_at' => $changedAt,
                'deleted' => 'n'
            ]
        );

        $contact->id = $this->db->lastInsertId();

        foreach ($contact->contact_address as $contactAddress) {
            $this->db->insert(
                'contact_address',
                [
                    'contact_id' => $contact->id,
                    'type' => $contactAddress->type,
                    'address' => $contactAddress->address,
                    'changed_at' => $changedAt,
                    'deleted' => 'n'
                ]
            );
        }
    }

    /**
     * Update the given contact and perform a differential update on the associated addresses
     *
     * @param Contact $contact
     *
     * @return void
     */
    public function update(Contact $contact): void
    {
        $changedAt = (int) (new DateTime())->format("Uv");

        $this->db->update(
            'contact',
            [
                'full_name' => $contact->full_name,
                'username' => $contact->username,
                'default_channel_id' => null,
                'changed_at' => $changedAt
            ],
            ['id' => $contact->id]
        );

        $requiredAddresses = [];
        foreach ($contact->contact_address as $contactAddress) {
            $requiredAddresses[$contactAddress->type] = $contactAddress->address;
        }

        $currentAddresses = ContactAddress::on($this->db)
            ->filter(Filter::equal('contact_id', $contact->id))
            ->filter(Filter::equal('deleted', 'n'));
        foreach ($currentAddresses as $contactAddress) {
            if (isset($requiredAddresses[$contactAddress->type])) {
                $this->db->update(
                    'contact_address',
                    [
                        'address' => $requiredAddresses[$contactAddress->type],
                        'changed_at' => $changedAt
                    ],
                    ['id = ?' => $contactAddress->id, 'deleted' => 'n']
                );

                unset($requiredAddresses[$contactAddress->type]);
            } else {
                $this->db->update(
                    'contact_address',
                    ['changed_at' => $changedAt, 'deleted' => 'y'],
                    ['id = ?' => $contactAddress->id, 'deleted' => 'n']
                );
            }
        }

        foreach ($requiredAddresses as $type => $address) {
            $this->db->insert(
                'contact_address',
                [
                    'contact_id' => $contact->id,
                    'type' => $type,
                    'address' => $address,
                    'changed_at' => $changedAt,
                    'deleted' => 'n'
                ]
            );
        }
    }

    /**
     * Delete the given contact and all associated addresses
     *
     * @param Contact $contact
     *
     * @return void
     */
    public function delete(Contact $contact): void
    {
        foreach ($contact->rotation as $rotation) {
            $otherMembers = $rotation->member
                ->filter(Filter::all(
                    Filter::unequal('contact_id', $contact->id),
                    Filter::equal('deleted', 'n')
                ))
                ->execute();
            if (! $otherMembers->hasResult()) {
                (new RotationRepository($this->db))->delete($rotation);
            } else {
                $rotation->member = $otherMembers;
                (new RotationRepository($this->db))->update($rotation);
            }
        }

        $changedAt = (int) (new DateTime())->format("Uv");

        $escalationIds = $this->db->fetchCol(
            RuleEscalationRecipient::on($this->db)
                ->columns('rule_escalation_id')
                ->filter(Filter::all(
                    Filter::equal('contact_id', $contact->id),
                    Filter::equal('deleted', 'n')
                ))
                ->assembleSelect()
        );

        $this->db->update(
            'rule_escalation_recipient',
            ['changed_at' => $changedAt, 'deleted' => 'y'],
            ['contact_id' => $contact->id, 'deleted' => 'n']
        );

        if (! empty($escalationIds)) {
            $escalationIdsWithOtherRecipients = $this->db->fetchCol(
                RuleEscalationRecipient::on($this->db)
                    ->columns('rule_escalation_id')
                    ->filter(Filter::all(
                        Filter::equal('rule_escalation_id', $escalationIds),
                        Filter::equal('deleted', 'n')
                    ))->assembleSelect()
            );

            $toRemoveEscalations = array_diff($escalationIds, $escalationIdsWithOtherRecipients);

            if (! empty($toRemoveEscalations)) {
                $this->db->update(
                    'rule_escalation',
                    [
                        'position' => null,
                        'changed_at' => $changedAt,
                        'deleted' => 'y'
                    ],
                    ['id IN (?)' => $toRemoveEscalations]
                );
            }
        }

        $this->db->update(
            'contactgroup_member',
            ['changed_at' => $changedAt, 'deleted' => 'y'],
            ['contact_id' => $contact->id, 'deleted' => 'n']
        );

        $this->db->update(
            'contact_address',
            ['changed_at' => $changedAt, 'deleted' => 'y'],
            ['contact_id' => $contact->id, 'deleted' => 'n']
        );

        $this->db->update(
            'contact',
            [
                'username' => null,
                'changed_at' => $changedAt,
                'deleted' => 'y'
            ],
            ['id' => $contact->id, 'deleted' => 'n']
        );
    }
}
