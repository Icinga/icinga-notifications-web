<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\IncidentContact;
use Icinga\Module\Notifications\Model\JobQueue;
use Icinga\User;
use InvalidArgumentException;
use ipl\I18n\Translation;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Web\Url;
use LogicException;
use Ramsey\Uuid\UuidInterface;

/**
 * Manage an incident's recipients and read its state
 *
 * Role changes are not applied immediately. Each of them is queued as a job, which the daemon processes
 * asynchronously, usually within a few seconds. Until then, reading the incident's state, e.g. with
 * {@see static::getRole()} or {@see static::getSubscribers()}, does not reflect the change.
 * The jobs are tracked for the current user, so the requested change can be shown as pending until it is
 * processed, see {@see static::getPendingAction()} and {@see static::getPendingJobAttributes()}.
 *
 * The daemon applies a role change to the currently open incident of the incident's object. So if the incident
 * recovers in the meantime, the change has no effect, or if the object has a newer open incident, applies to that.
 */
class Incident
{
    use Translation;

    /** @var ?IncidentModel The managed incident, null if it wasn't fetched yet */
    private ?IncidentModel $incident = null;

    /** @var ?Query<IncidentModel> The query to lazy load the incident */
    private ?Query $query = null;

    /** @var Connection The database connection to use */
    private Connection $db;

    /** @var JobTracker The tracker to remember queued jobs in across requests */
    private JobTracker $jobTracker;

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
     * @param JobTracker $jobTracker
     *
     * @return static
     */
    public static function fromQuery(Query $query, JobTracker $jobTracker): static
    {
        $incident = new static();
        $incident->query = $query;
        $incident->db = $query->getDb();
        $incident->jobTracker = $jobTracker;

        return $incident;
    }

    /**
     * Create an instance from an {@see IncidentModel}
     *
     * Instances created with this factory will never throw an {@see IncidentNotFoundException}
     *
     * @param IncidentModel $model
     * @param Connection $db
     * @param JobTracker $jobTracker
     *
     * @return static
     */
    public static function fromModel(IncidentModel $model, Connection $db, JobTracker $jobTracker): static
    {
        $incident = new static();
        $incident->incident = $model;
        $incident->db = $db;
        $incident->jobTracker = $jobTracker;

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
     * Request to add the contact with the given username as manager
     *
     * Nothing is requested if the incident already has a manager, whether it is that contact or another one,
     * see {@see static::hasManager()}. The change is applied asynchronously, see {@see static::getPendingAction()}.
     *
     * @param string $username
     * @param ?UuidInterface $uuid The id of the event this role change represents, to correlate it with the history of
     *                             its source, e.g. an acknowledgement. A new one is generated if not given.
     *
     * @return $this
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
     */
    public function addManager(string $username, ?UuidInterface $uuid = null): static
    {
        if (! $this->hasManager()) {
            $this->requestRoleChange($username, 'manage', ['manager'], $uuid);
        }

        return $this;
    }

    /**
     * Request to add the contact with the given username as subscriber
     *
     * Nothing is requested if the contact is already a subscriber or a manager. The change is applied asynchronously,
     * see {@see static::getPendingAction()}.
     *
     * @param string $username
     * @param ?UuidInterface $uuid The id of the event this role change represents, to correlate it with the history of
     *                             its source, e.g. an acknowledgement. A new one is generated if not given.
     *
     * @return $this
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
     */
    public function addSubscriber(string $username, ?UuidInterface $uuid = null): static
    {
        $this->requestRoleChange($username, 'subscribe', ['subscriber', 'manager'], $uuid);

        return $this;
    }

    /**
     * Request to demote the incident's manager to subscriber, whoever it is
     *
     * Nothing is requested if the incident has no manager. To only demote a specific user, check their role with
     * {@see static::getRole()} first. The change is applied asynchronously, see {@see static::getPendingAction()}.
     *
     * @param ?UuidInterface $uuid The id of the event this role change represents, to correlate it with the history of
     *                             its source, e.g. an acknowledgement. A new one is generated if not given.
     *
     * @return $this
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function removeManager(?UuidInterface $uuid = null): static
    {
        $managerId = $this->managerId();
        if ($managerId !== null) {
            $this->queueJob('unmanage', $managerId, $uuid);
        }

        return $this;
    }

    /**
     * Request to remove the subscriber with the given username
     *
     * Nothing is requested if the contact is not a subscriber. The change is applied asynchronously,
     * see {@see static::getPendingAction()}.
     *
     * @param string $username
     * @param ?UuidInterface $uuid The id of the event this role change represents, to correlate it with the history of
     *                             its source, e.g. an acknowledgement. A new one is generated if not given.
     *
     * @return $this
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     * @throws InvalidArgumentException If no contact with that username exists
     */
    public function removeSubscriber(string $username, ?UuidInterface $uuid = null): static
    {
        $this->requestRoleChange($username, 'unsubscribe', [null, 'recipient', 'manager'], $uuid);

        return $this;
    }

    /**
     * Get the role change for the current user that is currently queued in the `job_queue` table
     *
     * @return 'manage'|'unmanage'|'subscribe'|'unsubscribe'|null `null` if no role change is pending
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function getPendingAction(): ?string
    {
        return $this->jobTracker->getPending($this->incident()->id)['action'] ?? null;
    }

    /**
     * Get the attributes to render the element of a quick action while a role change is pending
     *
     * They disable the element and mark it so quick-action.js polls the state of its job and reloads the element's
     * container once the job has been processed.
     *
     * @return array
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function getPendingJobAttributes(): array
    {
        $job = $this->jobTracker->getPending($this->incident()->id);
        if ($job === null) {
            return [];
        }

        return [
            'disabled' => true,
            'title' => $this->translate('Your request is being processed'),
            'data-notifications-job' => Url::fromPath('notifications/job/state', ['id' => $job['job_id']])
                ->getAbsoluteUrl()
        ];
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
     * Get whether the incident has a manager
     *
     * @return bool
     *
     * @throws IncidentNotFoundException If the query passed to {@see static::fromQuery()} has no result
     */
    public function hasManager(): bool
    {
        return $this->managerId() !== null;
    }

    /**
     * Get the contact id of the incident's manager
     *
     * @return ?int null if the incident has no manager
     */
    private function managerId(): ?int
    {
        return IncidentContact::on($this->db)
            ->columns('contact_id')
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident()->id),
                Filter::equal('role', 'manager')
            ))
            ->first()
            ?->contact_id;
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
     * Get the role of the given contact for the incident, null if the contact has no `incident_contact` entry
     *
     * @param int $contactId
     *
     * @return ?string
     */
    private function existingRole(int $contactId): ?string
    {
        return IncidentContact::on($this->db)
            ->columns('role')
            ->filter(Filter::all(
                Filter::equal('incident_id', $this->incident()->id),
                Filter::equal('contact_id', $contactId)
            ))
            ->first()
            ?->role;
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
     * Request the given role change for the contact with the given username, unless it is a no-op
     *
     * The no-op checks are required as the daemon's rules differ from the ones documented on the public methods.
     * It e.g. accepts a `subscribe` of the manager, and then demotes the manager to subscriber.
     *
     * @param string $username
     * @param 'manage'|'subscribe'|'unsubscribe' $action The action to perform
     * @param array<?string> $noopRoles Existing roles for which this is a no-op, `null` matches an absent contact
     * @param ?UuidInterface $uuid The job's id, a new one is generated if not given
     *
     * @return $this
     */
    private function requestRoleChange(
        string $username,
        string $action,
        array $noopRoles,
        ?UuidInterface $uuid = null
    ): static {
        $contactId = $this->getContactByName($username)->id;
        if (! in_array($this->existingRole($contactId), $noopRoles, true)) {
            $this->queueJob($action, $contactId, $uuid);
        }

        return $this;
    }

    /**
     * Queue a job to perform the given action for the given contact in the `job_queue` table and track it
     *
     * The job identifies the incident by the object's id tags, so the incident must have been loaded with
     * `object.id_tags`. {@see static::consumeQuery()} and {@see Incidents} take care of that.
     *
     * @param 'manage'|'unmanage'|'subscribe'|'unsubscribe' $action The action to perform
     * @param int $contactId The id of the contact whose role is changed
     * @param ?UuidInterface $uuid The job's id, a new one is generated if not given
     *
     * @return void
     */
    private function queueJob(string $action, int $contactId, ?UuidInterface $uuid = null): void
    {
        $job = JobQueue::fromQuickAction($action, $contactId, $this->incident()->object->id_tags, $uuid);
        (new EntityManager($this->db))->save($job);
        $this->jobTracker->track($this->incident()->id, $action, $job->id);
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

        $query = $this->query->withColumns('object.id_tags');
        $this->query = null;

        return $query;
    }
}
