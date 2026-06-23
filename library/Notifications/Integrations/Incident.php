<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use DateTime;
use Generator;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\IncidentContact;
use Icinga\Module\Notifications\Model\IncidentHistory;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

/**
 * Manage an incident's recipients and read its state
 */
class Incident
{
    /** @var IncidentModel The managed incident */
    private IncidentModel $incident;

    /** @var Connection The database connection to use */
    private Connection $db;

    /**
     * Create a new wrapper for the given model
     *
     * @param IncidentModel $incident
     * @param Connection $db The connection to read and persist through
     */
    public function __construct(IncidentModel $incident, Connection $db)
    {
        $this->incident = $incident;
        $this->db = $db;
    }

    /**
     * Add the contact with the given username as manager
     *
     * Has no effect if the contact is already a manager.
     *
     * @param string $username
     *
     * @return $this
     */
    public function addManager(string $username): static
    {
        $this->assignRole($username, 'manager', ['manager']);

        return $this;
    }

    /**
     * Add the contact with the given username as subscriber
     *
     * Has no effect if the contact is already a subscriber or a manager.
     *
     * @param string $username
     *
     * @return $this
     */
    public function addSubscriber(string $username): static
    {
        $this->assignRole($username, 'subscriber', ['subscriber', 'manager']);

        return $this;
    }

    /**
     * Demote the manager with the given username to subscriber
     *
     * Has no effect if the contact is not a manager of the incident.
     *
     * @param string $username
     *
     * @return $this
     */
    public function removeManager(string $username): static
    {
        $this->assignRole($username, 'subscriber', [null, 'recipient', 'subscriber']);

        return $this;
    }

    /**
     * Remove the subscriber with the given username
     *
     * Has no effect if the contact is not a subscriber.
     *
     * @param string $username
     *
     * @return $this
     *
     * @throws InvalidArgumentException If no contact with that username exists
     */
    public function removeSubscriber(string $username): static
    {
        $contact = $this->getContactByName($username);
        $existing = $this->existingContact($contact->id);

        if ($existing?->role !== 'subscriber') {
            return $this;
        }

        (new EntityManager($this->db))->save($existing->markDeleted());
        $this->addRoleChangedHistory($contact->id, 'subscriber', null);

        return $this;
    }

    /**
     * Yield each active subscriber of the incident
     *
     * @return Generator<int, array{
     *     type: 'contact'|'contactgroup'|'schedule',
     *     name: string,
     *     full_name: ?string,
     *     role: 'manager'|'subscriber',
     *     roleChangedAt: DateTime}>
     */
    public function getSubscribers(): Generator
    {
        foreach ($this->resolveRecipients(['manager', 'subscriber']) as $recipient) {
            yield [
                'type'          => $recipient['type'],
                'name'          => $recipient['name'],
                'full_name'     => $recipient['full_name'],
                'role'          => $recipient['role'],
                'roleChangedAt' => $recipient['roleChangedAt'],
            ];
        }
    }

    /**
     * Yield each configured recipient of the incident
     *
     * @return Generator<int, array{
     *     type: 'contact'|'contactgroup'|'schedule',
     *     name: string,
     *     full_name: ?string,
     *     roleChangedAt: DateTime}>
     */
    public function getRecipients(): Generator
    {
        foreach ($this->resolveRecipients(['recipient']) as $recipient) {
            yield [
                'type'          => $recipient['type'],
                'name'          => $recipient['name'],
                'full_name'     => $recipient['full_name'],
                'roleChangedAt' => $recipient['roleChangedAt']
            ];
        }
    }

    /**
     * Get whether the incident is muted
     *
     * @return bool
     */
    public function isMuted(): bool
    {
        return $this->incident->mute_reason !== null;
    }

    /**
     * Load the contact with the given username
     *
     * @param string $username
     *
     * @return Contact
     *
     * @throws InvalidArgumentException If no contact with that username exists
     */
    private function getContactByName(string $username): Contact
    {
        /** @var ?Contact $contact */
        $contact = Contact::on($this->db)->filter(Filter::equal('username', $username))->first();

        if ($contact === null) {
            throw new InvalidArgumentException(sprintf('There is no contact with the username "%s"', $username));
        }

        return $contact;
    }

    /**
     * Load the incident's `incident_contact` entry for the given contact id
     *
     * @param int $contactId
     *
     * @return ?IncidentContact
     */
    private function existingContact(int $contactId): ?IncidentContact
    {
        /** @var ?IncidentContact $entry */
        $entry = IncidentContact::on($this->db)
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident->id),
                Filter::equal('contact_id', $contactId)
            ))
            ->first();

        return $entry;
    }

    /**
     * Resolve the incident's recipients who match any of the given roles
     *
     * @param string[] $roles
     *
     * @return list<array{
     *     type: 'contact'|'contactgroup'|'schedule',
     *     id: int,
     *     name: string,
     *     full_name: ?string,
     *     role: 'manager'|'subscriber'|'recipient',
     *     roleChangedAt: DateTime
     * }>
     */
    private function resolveRecipients(array $roles): array
    {
        $entries = IncidentContact::on($this->db)
            ->with(['contact', 'contactgroup', 'schedule'])
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident->id),
                Filter::equal('role', $roles),
                Filter::any(
                    Filter::unlike('contact_id', '*'),
                    Filter::equal('contact.deleted', false)
                ),
                Filter::any(
                    Filter::unlike('contactgroup_id', '*'),
                    Filter::equal('contactgroup.deleted', false)
                ),
                Filter::any(
                    Filter::unlike('schedule_id', '*'),
                    Filter::equal('schedule.deleted', false)
                )
            ));

        $recipients = [];
        foreach ($entries as $entry) {
            if ($entry->contact_id !== null) {
                $recipients[] = [
                    'type'      => 'contact',
                    'id'        => $entry->contact_id,
                    'name'      => $entry->contact->username,
                    'full_name' => $entry->contact->full_name,
                    'role'      => $entry->role,
                    'roleChangedAt' => $entry->changed_at
                ];
            } elseif ($entry->contactgroup_id !== null) {
                $recipients[] = [
                    'type'      => 'contactgroup',
                    'id'        => $entry->contactgroup_id,
                    'name'      => $entry->contactgroup->name,
                    'full_name' => null,
                    'role'      => $entry->role,
                    'roleChangedAt' => $entry->changed_at
                ];
            } elseif ($entry->schedule_id !== null) {
                $recipients[] = [
                    'type'      => 'schedule',
                    'id'        => $entry->schedule_id,
                    'name'      => $entry->schedule->name,
                    'full_name' => null,
                    'role'      => $entry->role,
                    'roleChangedAt' => $entry->changed_at
                ];
            }
        }

        return $recipients;
    }

    /**
     * Set the contact's role, appending a new `incident_contact` entry if it has none yet
     *
     * @param string $username
     * @param string $role The role to assign
     * @param array<?string> $noopRoles Existing roles for which this is a no-op, `null` matches an absent contact
     *
     * @return $this
     *
     * @throws InvalidArgumentException If no contact with that username exists
     */
    private function assignRole(string $username, string $role, array $noopRoles): static
    {
        $contact = $this->getContactByName($username);
        $existing = $this->existingContact($contact->id);

        if (in_array($existing?->role, $noopRoles, true)) {
            return $this;
        }

        $oldRole = $existing?->role;

        if ($existing !== null) {
            $existing->role = $role;
            (new EntityManager($this->db))->save($existing);
        } else {
            $incidentContact = new IncidentContact();
            $incidentContact->incident_id = $this->incident->id;
            $incidentContact->contact_id = $contact->id;
            $incidentContact->role = $role;
            (new EntityManager($this->db))->save($incidentContact);
        }

        $this->addRoleChangedHistory($contact->id, $oldRole, $role);

        return $this;
    }

    /**
     * Persist a `recipient_role_changed` history entry for the incident
     *
     * @param int $contactId
     * @param ?string $oldRole
     * @param ?string $newRole
     *
     * @return void
     */
    private function addRoleChangedHistory(int $contactId, ?string $oldRole, ?string $newRole): void
    {
        $history = new IncidentHistory();
        $history->incident_id = $this->incident->id;
        $history->contact_id = $contactId;
        $history->type = 'recipient_role_changed';
        $history->old_recipient_role = $oldRole;
        $history->new_recipient_role = $newRole;
        $history->time = new DateTime();
        (new EntityManager($this->db))->save($history);
    }
}
