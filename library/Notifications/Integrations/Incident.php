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
use ipl\Stdlib\Seq;

/**
 * Manage an incident's recipients and read its state
 *
 * Changes are in memory until persisted by {@see save()}.
 */
class Incident
{
    /** @var IncidentModel The managed incident */
    private IncidentModel $incident;

    /** @var Connection The database connection to use */
    private Connection $db;

    /** @var IncidentHistory[] History rows created in memory, pending the next {@see save()} */
    private array $pendingHistory = [];

    /** @var IncidentContact[]|null `incident_contact` entries loaded and mutated in memory, flushed on {@see save()} */
    private ?array $incidentContacts = null;

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
        $contact = $this->getContactByName($username);
        $contacts = $this->incidentContacts();
        $existing = $this->findIncidentContact($contacts, $contact->id);

        if ($existing?->role !== 'manager') {
            return $this;
        }

        $existing->role = 'subscriber';

        $this->incidentContacts = $contacts;
        $this->addRoleChangedHistory($contact->id, 'manager', 'subscriber');

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
     */
    public function removeSubscriber(string $username): static
    {
        $contact = $this->getContactByName($username);
        $contacts = $this->incidentContacts();
        $existing = $this->findIncidentContact($contacts, $contact->id);

        if ($existing === null || $existing->role !== 'subscriber') {
            return $this;
        }

        $this->incidentContacts = array_values(
            array_filter($contacts, fn(IncidentContact $entry) => $entry !== $existing)
        );
        $this->incident->deleteOnSave($existing);
        $this->addRoleChangedHistory($contact->id, 'subscriber', null);

        return $this;
    }

    /**
     * Yield the username and full name of each of the managers
     *
     * @return Generator<int, array{username: string, full_name: string}>
     */
    public function getManagers(): Generator
    {
        yield from $this->contactsWithRole('manager');
    }

    /**
     * Yield the username and full name of each of the subscribers
     *
     * @return Generator<int, array{username: string, full_name: string}>
     */
    public function getSubscribers(): Generator
    {
        yield from $this->contactsWithRole('subscriber');
    }

    /**
     * Yield the username and full name of each contact that was notified
     *
     * @return Generator<int, array{username: string, full_name: string}>
     */
    public function getNotifiedContacts(): Generator
    {
        $history = IncidentHistory::on($this->db)
            ->columns(['contact_id'])
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident->id),
                Filter::equal('type', 'notified')
            ));

        $contactIds = [];
        foreach ($history as $entry) {
            if ($entry->contact_id !== null) {
                $contactIds[$entry->contact_id] = true;
            }
        }

        yield from $this->namesOf($contactIds);
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
     * Persist the pending changes to the database
     *
     * @return $this
     */
    public function save(): static
    {
        $this->incident->incident_history = $this->pendingHistory;
        $this->pendingHistory = [];

        if ($this->incidentContacts !== null) {
            $this->incident->incident_contact = $this->incidentContacts;
            $this->incidentContacts = null;
        }

        (new EntityManager($this->db))->save($this->incident);

        return $this;
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
     * Find the entry for the given contact id among the supplied `incident_contact` entries
     *
     * @param IncidentContact[] $contacts
     * @param int $contactId
     *
     * @return ?IncidentContact
     */
    private function findIncidentContact(array $contacts, int $contactId): ?IncidentContact
    {
        foreach ($contacts as $entry) {
            if ($entry->contact_id === $contactId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Materialize the `incident_contact` relation into a list
     *
     * @return IncidentContact[]
     */
    private function incidentContacts(): array
    {
        if ($this->incidentContacts === null) {
            $this->incidentContacts = [];
            if (isset($this->incident->incident_contact)) {
                foreach ($this->incident->incident_contact as $entry) {
                    $this->incidentContacts[] = $entry;
                }
            }
        }

        return $this->incidentContacts;
    }

    /**
     * Yield the username and full name of each contact that has the given role
     *
     * @param string $role
     *
     * @return Generator<int, array{username: string, full_name: string}>
     */
    private function contactsWithRole(string $role): Generator
    {
        $contactIds = [];
        foreach ($this->incidentContacts() as $entry) {
            if ($entry->role === $role && $entry->contact_id !== null) {
                $contactIds[$entry->contact_id] = true;
            }
        }

        yield from $this->namesOf($contactIds);
    }

    /**
     * Resolve the username and full name for the given contact ids in a single query
     *
     * @param array<int, true> $contactIds Set of contact ids keyed by the id
     *
     * @return Generator<int, array{username: string, full_name: string}>
     */
    private function namesOf(array $contactIds): Generator
    {
        if (empty($contactIds)) {
            return;
        }

        $contacts = Contact::on($this->db)
            ->columns(['id', 'username', 'full_name'])
            ->filter(Filter::equal('id', array_keys($contactIds)));

        yield from Seq::map(
            $contacts,
            fn($contact) => ['username' => $contact->username, 'full_name' => $contact->full_name]
        );
    }

    /**
     * Set the contact's role, appending a new `incident_contact` entry if it has none yet
     *
     * @param string $username
     * @param string $role The role to assign
     * @param string[] $noopRoles Existing roles for which this is a no-op
     *
     * @return $this
     */
    private function assignRole(string $username, string $role, array $noopRoles): static
    {
        $contact = $this->getContactByName($username);
        $contacts = $this->incidentContacts();
        $existing = $this->findIncidentContact($contacts, $contact->id);

        if ($existing !== null && in_array($existing->role, $noopRoles, true)) {
            return $this;
        }

        $oldRole = $existing?->role;

        if ($existing !== null) {
            $existing->role = $role;
        } else {
            $incidentContact = new IncidentContact();
            $incidentContact->contact_id = $contact->id;
            $incidentContact->role = $role;
            $contacts[] = $incidentContact;
        }

        $this->incidentContacts = $contacts;
        $this->addRoleChangedHistory($contact->id, $oldRole, $role);

        return $this;
    }

    /**
     * Append a `recipient_role_changed` entry to the pending history, to be persisted on the next save()
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
        $history->contact_id = $contactId;
        $history->type = 'recipient_role_changed';
        $history->old_recipient_role = $oldRole;
        $history->new_recipient_role = $newRole;
        $history->time = new DateTime();
        $this->pendingHistory[] = $history;
    }
}
