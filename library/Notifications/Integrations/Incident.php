<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use DateTime;
use Exception;
use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\IncidentContact;
use ipl\Stdlib\Filter;

class Incident
{
    protected IncidentModel $incident;

    public function __construct(IncidentModel $incident)
    {
        $this->incident = $incident;
    }

    /**
     * Add the contact as manager of the incident
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function addManager(Contact $contact): static
    {
        $this->setRole($contact, 'manager');

        return $this;
    }

    /**
     * Change the contact's role from manager to subscriber
     *
     * This has no effect if the contact is not a manager of the incident
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function removeManager(Contact $contact): static
    {
        $existing = $this->fetchIncidentContact($contact);
        if ($existing?->role !== 'manager') {
            return $this;
        }

        $this->setRole($contact, 'subscriber', $existing);
        return $this;
    }

    /**
     * Add the contact as a subscriber
     *
     * This has no effect if the contact is already a subscriber or a manager
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function addSubscriber(Contact $contact): static
    {
        $existing = $this->fetchIncidentContact($contact);
        if ($existing?->role === 'subscriber' || $existing?->role === 'manager') {
            return $this;
        }

        $this->setRole($contact, 'subscriber', $existing);
        return $this;
    }

    /**
     * Remove the contact as a subscriber
     *
     * This will delete the incident_contact row. Has no effect if the contact is not a subscriber.
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function removeSubscriber(Contact $contact): static
    {
        $db = Database::get();
        $existing = $this->fetchIncidentContact($contact);
        if ($existing === null || $existing->role !== 'subscriber') {
            return $this;
        }

        $db->beginTransaction();
        try {
            $db->delete('incident_contact', [
                'incident_id = ?' => $this->incident->id,
                'contact_id = ?'  => $contact->id,
                'role = ?'        => 'subscriber'
            ]);

            $this->insertHistory($contact->id, 'subscriber', null);
        } catch (Exception $e) {
            $db->rollBackTransaction();
            throw $e;
        }

        $db->commitTransaction();
        return $this;
    }

    /**
     * Get all managers of the incident
     *
     * @return Generator<int, IncidentContact>
     */
    public function getManagers(): Generator
    {
        yield from $this->fetchContactsByRole('manager');
    }

    /**
     * Get all subscribers of the incident
     *
     * @return Generator<int, IncidentContact>
     */
    public function getSubscribers(): Generator
    {
        yield from $this->fetchContactsByRole('subscriber');
    }

    /**
     * Get all contacts that were notified about this incident
     *
     * @return Generator<int, Contact>
     */
    public function getNotifiedContacts(): Generator
    {
        $query = Contact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('incident_history.incident_id', $this->incident->id),
                Filter::equal('incident_history.type', 'notified')
            ));
        $query->getSelectBase()->distinct();

        yield from $query;
    }

    public function isMuted(): bool
    {
        return $this->incident->mute_reason !== null;
    }

    protected function setRole(Contact $contact, string $role, ?IncidentContact $existing = null): void
    {
        $db = Database::get();
        if ($existing === null) {
            $existing = $this->fetchIncidentContact($contact);
        }

        $oldRole = $existing?->role;

        if ($oldRole === $role) {
            return;
        }

        $db->beginTransaction();
        try {
            if ($existing !== null) {
                $db->update(
                    'incident_contact',
                    ['role' => $role],
                    [
                        'incident_id = ?' => $this->incident->id,
                        'contact_id = ?'  => $contact->id
                    ]
                );
            } else {
                $db->insert('incident_contact', [
                    'incident_id' => $this->incident->id,
                    'contact_id'  => $contact->id,
                    'role'        => $role
                ]);
            }

            $this->insertHistory($contact->id, $oldRole, $role);
        } catch (Exception $e) {
            $db->rollBackTransaction();
            throw $e;
        }

        $db->commitTransaction();
    }

    protected function fetchIncidentContact(Contact $contact): ?IncidentContact
    {
        /** @var ?IncidentContact $entry */
        $entry = IncidentContact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident->id),
                Filter::equal('contact_id', $contact->id)
            ))
            ->first();

        return $entry;
    }

    /**
     * Fetch all contacts that have the given role
     *
     * @return Generator<int, IncidentContact>
     */
    protected function fetchContactsByRole(string $role): Generator
    {
        yield from IncidentContact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident->id),
                Filter::equal('role', $role)
            ));
    }

    protected function insertHistory(int $contactId, ?string $oldRole, ?string $newRole): void
    {
        $now = new DateTime();
        Database::get()->insert(
            'incident_history',
            [
                'incident_id'        => $this->incident->id,
                'contact_id'         => $contactId,
                'type'               => 'recipient_role_changed',
                'new_recipient_role' => $newRole,
                'old_recipient_role' => $oldRole,
                'time'               => (int) $now->format('Uv')
            ]
        );
    }
}
