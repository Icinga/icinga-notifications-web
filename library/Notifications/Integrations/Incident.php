<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use DateTime;
use Generator;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\IncidentContact;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\User;
use InvalidArgumentException;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use LogicException;

/**
 * Manage an incident's recipients and read its state
 */
class Incident
{
    /** @var ?IncidentModel The managed incident, null if it wasn't fetched yet */
    private ?IncidentModel $incident = null;

    /** @var ?Query<IncidentModel> The query to lazy load the incident */
    private ?Query $query = null;

    /** @var Connection The database connection to use */
    private Connection $db;

    private function __construct()
    {
    }

    /**
     * Create an instance from a query that should return one incident
     *
     * If the query does not return an incident, calling any function on the created instance throws an
     * {@see IncidentNotFoundException}
     *
     * @param Query<IncidentModel> $query
     *
     * @return static
     */
    public static function fromQuery(Query $query): static
    {
        $incident = new static();
        $incident->query = $query;
        $incident->db = $query->getDb();

        return $incident;
    }

    /**
     * Create an instance from an {@see IncidentModel}
     *
     * Instances created with this factory will never throw an {@see IncidentNotFoundException}
     *
     * @param IncidentModel $model
     * @param Connection $db
     *
     * @return static
     */
    public static function fromModel(IncidentModel $model, Connection $db): static
    {
        $incident = new static();
        $incident->incident = $model;
        $incident->db = $db;

        return $incident;
    }

    /**
     * Get the given user's role for the incident, null if the user has no role, throws if no matching incident exists
     *
     * @param User $user
     *
     * @return 'manager'|'subscriber'|'recipient'|null
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function getRole(User $user): ?string
    {
        if ($this->incident === null) {
            $incidentContactTable = (new IncidentContact())->getTableName();
            $contactTable = (new Contact())->getTableName();
            $query = $this->consumeQuery()
                ->withColumns(['role' =>
                    new Expression(
                        "(SELECT ic.role FROM $incidentContactTable AS ic"
                        . " JOIN $contactTable AS c ON ic.contact_id = c.id"
                        . " WHERE c.username = ? AND c.deleted = 'n' AND ic.incident_id = %s)",
                        ['id'],
                        $user->getUsername()
                    )
                ]);

            $this->incident = $query->first();
            if ($this->incident === null) {
                throw new IncidentNotFoundException('No matching incident was found');
            }

            return $this->incident->role;
        } else {
            return IncidentContact::on($this->db)
                ->columns('role')
                ->filter(Filter::all(
                    Filter::equal('incident_id', $this->incident()->id),
                    Filter::equal('contact.username', $user->getUsername())
                ))
                ->first()
                ?->role;
        }
    }

    /**
     * Add the contact with the given username as manager
     *
     * Has no effect if the contact is already a manager.
     *
     * @param string $username
     *
     * @return $this
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
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
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
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
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
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
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
     */
    public function removeSubscriber(string $username): static
    {
        $contact = $this->getContactByName($username);
        $existing = $this->existingContact($contact->id);

        if ($existing?->role !== 'subscriber') {
            return $this;
        }

        (new EntityManager($this->db))->save($existing->delete());
        $this->addRoleChangedHistory($contact->id, 'subscriber', null);

        return $this;
    }

    /**
     * Yield each active subscriber of the incident
     *
     * @return array<int, array{
     *     name: string,
     *     username: ?string,
     *     role: 'manager'|'subscriber',
     *     roleChangedAt: DateTime}>
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function getSubscribers(): array
    {
        return array_map(
            function ($recipient) {
                return [
                    'name'          => $recipient['name'],
                    'username'      => $recipient['username'],
                    'role'          => $recipient['role'],
                    'roleChangedAt' => $recipient['roleChangedAt']
                ];
            },
            $this->resolveRecipients(['manager', 'subscriber'])
        );
    }

    /**
     * Yield each configured recipient of the incident
     *
     * @return array<int, array{
     *     type: 'contact'|'contactgroup'|'schedule',
     *     name: string,
     *     username: ?string,
     *     roleChangedAt: DateTime}>
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function getRecipients(): array
    {
        return array_map(
            function ($recipient) {
                return [
                    'type'          => $recipient['type'],
                    'name'          => $recipient['name'],
                    'username'      => $recipient['username'],
                    'roleChangedAt' => $recipient['roleChangedAt']
                ];
            },
            $this->resolveRecipients(['recipient'])
        );
    }

    /**
     * Get whether the incident is muted
     *
     * @return bool
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function isMuted(): bool
    {
        return $this->incident()->mute_reason !== null;
    }

    /**
     * Load the contact with the given username
     *
     * @param string $username
     *
     * @return Contact
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
                Filter::equal('incident_id', $this->incident()->id),
                Filter::equal('contact_id', $contactId)
            ))
            ->first()
            ?->setNew(false);

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
     *     username: ?string,
     *     role: 'manager'|'subscriber'|'recipient',
     *     roleChangedAt: DateTime
     * }>
     */
    private function resolveRecipients(array $roles): array
    {
        $entries = IncidentContact::on($this->db)
            ->with(['contact', 'contactgroup', 'schedule'])
            ->filter(
                Filter::all(
                    Filter::equal('incident_id', $this->incident()->id),
                    Filter::equal('role', $roles)
                )
            );

        $recipients = [];
        foreach ($entries as $entry) {
            if (isset($entry->contact->id)) {
                $recipients[] = [
                    'type'      => 'contact',
                    'id'        => $entry->contact_id,
                    'name'      => $entry->contact->full_name,
                    'username'  => $entry->contact->username,
                    'role'      => $entry->role,
                    'roleChangedAt' => $entry->changed_at
                ];
            } elseif (isset($entry->contactgroup->id)) {
                $recipients[] = [
                    'type'      => 'contactgroup',
                    'id'        => $entry->contactgroup_id,
                    'name'      => $entry->contactgroup->name,
                    'username'  => null,
                    'role'      => $entry->role,
                    'roleChangedAt' => $entry->changed_at
                ];
            } elseif (isset($entry->schedule->id)) {
                $recipients[] = [
                    'type'      => 'schedule',
                    'id'        => $entry->schedule_id,
                    'name'      => $entry->schedule->name,
                    'username'  => null,
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
            $incidentContact = (new IncidentContact())->setNew();
            $incidentContact->incident_id = $this->incident()->id;
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
        $history = (new IncidentHistory())->setNew();
        $history->incident_id = $this->incident()->id;
        $history->contact_id = $contactId;
        $history->type = 'recipient_role_changed';
        $history->old_recipient_role = $oldRole;
        $history->new_recipient_role = $newRole;
        $history->time = new DateTime();
        (new EntityManager($this->db))->save($history);
    }

    /**
     * Fetch the incident lazily and return it
     *
     * @return IncidentModel
     *
     * @throws IncidentNotFoundException
     */
    private function incident(): IncidentModel
    {
        if ($this->incident === null) {
            $this->incident = $this->consumeQuery()->first();
        }

        if ($this->incident === null) {
            throw new IncidentNotFoundException('No matching incident was found');
        }

        return $this->incident;
    }

    /**
     * Single use getter for the query to lazy load the incident
     *
     * @return Query<IncidentModel>
     *
     * @throws LogicException If the query has already been consumed
     */
    private function consumeQuery(): Query
    {
        if ($this->query === null) {
            throw new LogicException(
                'Cannot fetch the incident again, the query has already been consumed.'
                . 'An earlier call probably failed with an IncidentNotFoundException.'
            );
        }

        $query = $this->query;
        $this->query = null;

        return $query;
    }
}
