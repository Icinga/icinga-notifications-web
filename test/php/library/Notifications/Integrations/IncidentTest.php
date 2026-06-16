<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use InvalidArgumentException;
use ipl\Stdlib\Filter;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;

/**
 * Contract of the integration-facing {@see Incident}: it is identified by usernames (never Contact
 * instances), and every write operation persists immediately, so the change is in the database the
 * moment the call returns.
 *
 * Its two recipient readers split the incident's `incident_contact` rows by role: {@see Incident::getSubscribers()}
 * yields the active subscribers (roles `manager` and `subscriber`), {@see Incident::getRecipients()} the
 * configured recipients (role `recipient`). Both are polymorphic — a recipient may be a contact, contact
 * group or schedule — and yield a uniform shape carrying a `type` discriminator, the display `name` and a
 * nullable `full_name`. Subscribers additionally carry their `role` and the `roleChangedAt` time their
 * current role was assigned (null when unknown); deleted contact groups and schedules are omitted from both.
 */
class IncidentTest extends TestCase
{
    private RecordingConnection $db;

    /**
     * Set up an in-memory SQLite database with the tables these operations touch. The integration
     * under test reads usernames and persists through the connection it is handed, so no global state
     * is involved.
     */
    protected function setUp(): void
    {
        $this->db = new RecordingConnection(['db' => 'sqlite', 'dbname' => ':memory:']);
        $this->db->exec(
            'CREATE TABLE incident (id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' object_id BLOB, started_at INTEGER, recovered_at INTEGER, severity VARCHAR, mute_reason VARCHAR);'
            . 'CREATE TABLE contact (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name VARCHAR, username VARCHAR,'
            . ' default_channel_id INTEGER, changed_at INTEGER, deleted VARCHAR, external_uuid VARCHAR);'
            . 'CREATE TABLE contactgroup (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, changed_at INTEGER,'
            . ' deleted VARCHAR, external_uuid VARCHAR);'
            . 'CREATE TABLE schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, changed_at INTEGER,'
            . ' timezone VARCHAR, deleted VARCHAR);'
            . 'CREATE TABLE incident_contact (incident_id INTEGER NOT NULL, contact_id INTEGER,'
            . ' contactgroup_id INTEGER, schedule_id INTEGER, role VARCHAR);'
            . 'CREATE TABLE incident_history (id INTEGER PRIMARY KEY AUTOINCREMENT, incident_id INTEGER NOT NULL,'
            . ' rule_id INTEGER, rule_escalation_id INTEGER, time INTEGER, type VARCHAR, contact_id INTEGER,'
            . ' schedule_id INTEGER, contactgroup_id INTEGER, channel_id INTEGER, new_severity VARCHAR,'
            . ' old_severity VARCHAR, new_recipient_role VARCHAR, old_recipient_role VARCHAR, message VARCHAR,'
            . ' notification_state VARCHAR, sent_at INTEGER);'
        );
    }

    public function testAddManagerAddsTheContactAsManagerByUsername(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $incident = $this->incident($id);
        $incident->addManager('uname');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'uname', 'full_name' => 'Uname Example', 'role' => 'manager']],
            $this->subscribersWithoutTimestamp($incident)
        );
        $this->assertSame(
            [['username' => 'uname', 'role' => 'manager']],
            $this->storedContactRoles(),
            'addManager() persists immediately'
        );
    }

    public function testAddManagerThrowsForAnUnknownUsername(): void
    {
        $id = $this->seedIncident();

        $this->expectException(InvalidArgumentException::class);

        $this->incident($id)->addManager('ghost');
    }

    public function testRemoveManagerDemotesTheManagerToSubscriber(): void
    {
        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $contactId, 'manager');

        $incident = $this->incident($id);
        $incident->removeManager('uname');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'uname', 'full_name' => 'Uname Example', 'role' => 'subscriber']],
            $this->subscribersWithoutTimestamp($incident)
        );
        $this->assertSame(
            [['username' => 'uname', 'role' => 'subscriber']],
            $this->storedContactRoles(),
            'removeManager() persists the demotion immediately'
        );
    }

    public function testAddSubscriberAddsTheContactAsSubscriberByUsername(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $incident = $this->incident($id);
        $incident->addSubscriber('uname');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'uname', 'full_name' => 'Uname Example', 'role' => 'subscriber']],
            $this->subscribersWithoutTimestamp($incident)
        );
        $this->assertSame(
            [['username' => 'uname', 'role' => 'subscriber']],
            $this->storedContactRoles(),
            'addSubscriber() persists immediately'
        );
    }

    public function testRemoveSubscriberDeletesTheSubscriberEntry(): void
    {
        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $contactId, 'subscriber');

        $incident = $this->incident($id);
        $incident->removeSubscriber('uname');

        $this->assertSame([], iterator_to_array($incident->getSubscribers(), false));
        $this->assertSame(
            [],
            $this->storedContactRoles(),
            'removeSubscriber() deletes the entry immediately'
        );
    }

    public function testGetSubscribersYieldsActiveSubscribersOfEachTypeWithTheirRole(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'subscriber');
        $this->seedIncidentContact($id, null, 'subscriber', contactgroupId: $this->seedContactgroup('windows-admins'));
        $this->seedIncidentContact($id, null, 'subscriber', scheduleId: $this->seedSchedule('On-Call'));

        $this->assertSame(
            [
                ['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example',
                    'role' => 'manager', 'roleChangedAt' => null],
                ['type' => 'contact', 'name' => 'bob', 'full_name' => 'Bob Example',
                    'role' => 'subscriber', 'roleChangedAt' => null],
                ['type' => 'contactgroup', 'name' => 'windows-admins', 'full_name' => null,
                    'role' => 'subscriber', 'roleChangedAt' => null],
                ['type' => 'schedule', 'name' => 'On-Call', 'full_name' => null,
                    'role' => 'subscriber', 'roleChangedAt' => null],
            ],
            $this->sortedByTypeAndName($this->incident($id)->getSubscribers())
        );
    }

    public function testGetSubscribersExcludesConfiguredRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'recipient');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example',
                'role' => 'manager', 'roleChangedAt' => null]],
            iterator_to_array($this->incident($id)->getSubscribers(), false)
        );
    }

    public function testGetSubscribersIgnoresRowsWithoutARecipientReference(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        // Neither contact, contact group nor schedule is referenced; such a row yields nothing.
        $this->seedIncidentContact($id, null, 'subscriber');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example',
                'role' => 'manager', 'roleChangedAt' => null]],
            iterator_to_array($this->incident($id)->getSubscribers(), false)
        );
    }

    public function testGetSubscribersOmitsDeletedRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('gone-contact', deleted: true), 'subscriber');
        $this->seedIncidentContact($id, null, 'subscriber', contactgroupId: $this->seedContactgroup('gone-group', deleted: true));
        $this->seedIncidentContact($id, null, 'subscriber', scheduleId: $this->seedSchedule('gone-schedule', deleted: true));

        $this->assertSame(
            [['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example',
                'role' => 'subscriber', 'roleChangedAt' => null]],
            iterator_to_array($this->incident($id)->getSubscribers(), false)
        );
    }

    public function testGetSubscribersResolvesRoleChangedAtFromTheRoleChangeHistory(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $this->seedIncidentContact($id, $alice, 'manager');
        $this->seedRoleChange($id, 'manager', 1700000000000, contactId: $alice);

        $subscribers = iterator_to_array($this->incident($id)->getSubscribers(), false);

        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(DateTime::class, $subscribers[0]['roleChangedAt']);
        $this->assertSame(1700000000, $subscribers[0]['roleChangedAt']->getTimestamp());

        unset($subscribers[0]['roleChangedAt']);
        $this->assertSame(
            ['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example', 'role' => 'manager'],
            $subscribers[0],
            'Apart from roleChangedAt the entry carries the uniform recipient shape'
        );
    }

    public function testGetSubscribersRoleChangedAtUsesTheMostRecentMatchingChange(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $this->seedIncidentContact($id, $alice, 'subscriber');
        $this->seedRoleChange($id, 'subscriber', 1700000000000, contactId: $alice);
        $this->seedRoleChange($id, 'subscriber', 1700000005000, contactId: $alice);

        $subscribers = iterator_to_array($this->incident($id)->getSubscribers(), false);

        $this->assertSame(1700000005, $subscribers[0]['roleChangedAt']->getTimestamp());
    }

    public function testGetSubscribersRoleChangedAtIgnoresChangesToOtherRoles(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $this->seedIncidentContact($id, $alice, 'manager');
        // A change into a role the contact no longer holds must not be reported as the current role's time.
        $this->seedRoleChange($id, 'subscriber', 1700000000000, contactId: $alice);

        $subscribers = iterator_to_array($this->incident($id)->getSubscribers(), false);

        $this->assertNull($subscribers[0]['roleChangedAt']);
    }

    public function testGetSubscribersRoleChangedAtResolvesForGroupsAndSchedules(): void
    {
        $id = $this->seedIncident();
        $group = $this->seedContactgroup('windows-admins');
        $schedule = $this->seedSchedule('On-Call');
        $this->seedIncidentContact($id, null, 'subscriber', contactgroupId: $group);
        $this->seedIncidentContact($id, null, 'subscriber', scheduleId: $schedule);
        $this->seedRoleChange($id, 'subscriber', 1700000000000, contactgroupId: $group);
        // The schedule has no role-change history, so its time stays null (the daemon gap).

        $byName = [];
        foreach ($this->incident($id)->getSubscribers() as $s) {
            $byName[$s['name']] = $s['roleChangedAt'];
        }

        $this->assertSame(1700000000, $byName['windows-admins']->getTimestamp());
        $this->assertNull($byName['On-Call']);
    }

    public function testGetRecipientsYieldsConfiguredRecipientsOfEachType(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'recipient');
        $this->seedIncidentContact($id, null, 'recipient', contactgroupId: $this->seedContactgroup('windows-admins'));
        $this->seedIncidentContact($id, null, 'recipient', scheduleId: $this->seedSchedule('On-Call'));

        $this->assertSame(
            [
                ['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example'],
                ['type' => 'contactgroup', 'name' => 'windows-admins', 'full_name' => null],
                ['type' => 'schedule', 'name' => 'On-Call', 'full_name' => null],
            ],
            $this->sortedByTypeAndName($this->incident($id)->getRecipients())
        );
    }

    public function testGetRecipientsExcludesActiveSubscribers(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('carol'), 'recipient');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'carol', 'full_name' => 'Carol Example']],
            iterator_to_array($this->incident($id)->getRecipients(), false)
        );
    }

    public function testGetRecipientsOmitsDeletedRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'recipient');
        $this->seedIncidentContact($id, $this->seedContact('gone-contact', deleted: true), 'recipient');
        $this->seedIncidentContact($id, null, 'recipient', contactgroupId: $this->seedContactgroup('gone-group', deleted: true));
        $this->seedIncidentContact($id, null, 'recipient', scheduleId: $this->seedSchedule('gone-schedule', deleted: true));

        $this->assertSame(
            [['type' => 'contact', 'name' => 'alice', 'full_name' => 'Alice Example']],
            iterator_to_array($this->incident($id)->getRecipients(), false)
        );
    }

    public function testIsMutedReflectsTheMuteReason(): void
    {
        $muted = $this->seedIncident('down for maintenance');
        $notMuted = $this->seedIncident();

        $this->assertTrue($this->incident($muted)->isMuted());
        $this->assertFalse($this->incident($notMuted)->isMuted());
    }

    public function testAddManagerWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->addManager('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'manager']],
            $this->storedRoleHistory()
        );
    }

    public function testRemoveManagerWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeManager('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'manager', 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    public function testAddSubscriberWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    public function testRemoveSubscriberWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'subscriber', 'new_role' => null]],
            $this->storedRoleHistory()
        );
    }

    public function testChainedRoleChangesEachWriteAHistoryRow(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('alice');
        $this->seedContact('bob');

        $this->incident($id)
            ->addManager('alice')
            ->addSubscriber('bob');

        $this->assertSame(
            [
                ['username' => 'alice', 'old_role' => null, 'new_role' => 'manager'],
                ['username' => 'bob', 'old_role' => null, 'new_role' => 'subscriber'],
            ],
            $this->storedRoleHistory()
        );
    }

    public function testASecondWriteDoesNotDuplicateHistoryFromAnEarlierWrite(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('alice');
        $this->seedContact('bob');

        $incident = $this->incident($id);
        $incident->addManager('alice');
        $incident->addSubscriber('bob');

        $this->assertSame(
            [
                ['username' => 'alice', 'old_role' => null, 'new_role' => 'manager'],
                ['username' => 'bob', 'old_role' => null, 'new_role' => 'subscriber'],
            ],
            $this->storedRoleHistory()
        );
    }

    public function testAddSubscriberDoesNotDemoteAnExistingManager(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testAddManagerPromotesAnExistingSubscriberInPlace(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addManager('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'subscriber', 'new_role' => 'manager']],
            $this->storedRoleHistory()
        );
    }

    public function testAddManagerOnAnExistingManagerIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addManager('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    public function testAddSubscriberOnAnExistingSubscriberIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    public function testRemoveManagerOfANonManagerIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeManager('uname');

        // A subscriber must not be demoted by removeManager, and no history is written.
        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveManagerWithoutAnEntryIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeManager('uname');

        $this->assertSame([], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveSubscriberOfANonSubscriberIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeSubscriber('uname');

        // A manager entry must not be deleted by removeSubscriber.
        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveSubscriberWithoutAnEntryIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame([], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    /**
     * Insert an incident and return its generated id.
     */
    private function seedIncident(?string $muteReason = null): int
    {
        $this->db->insert('incident', ['severity' => 'crit', 'mute_reason' => $muteReason]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a contact with the given username and return its generated id.
     *
     * The full name defaults to a distinct value derived from the username (e.g. "Alice Example" for
     * "alice"), so the readers' username/full_name pairing can be asserted unambiguously.
     */
    private function seedContact(string $username, bool $deleted = false): int
    {
        $fullName = ucfirst($username) . ' Example';

        $this->db->insert(
            'contact',
            ['full_name' => $fullName, 'username' => $username, 'deleted' => $deleted ? 'y' : 'n']
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a contact group with the given name and return its generated id.
     */
    private function seedContactgroup(string $name, bool $deleted = false): int
    {
        $this->db->insert('contactgroup', ['name' => $name, 'deleted' => $deleted ? 'y' : 'n']);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a schedule with the given name and return its generated id.
     */
    private function seedSchedule(string $name, bool $deleted = false): int
    {
        $this->db->insert('schedule', ['name' => $name, 'deleted' => $deleted ? 'y' : 'n']);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert an `incident_contact` row referencing exactly one recipient.
     *
     * Exactly one of $contactId, $contactgroupId or $scheduleId is expected to be set; the others stay
     * null, mirroring the polymorphic recipient key the daemon writes.
     */
    private function seedIncidentContact(
        int $incidentId,
        ?int $contactId,
        string $role,
        ?int $contactgroupId = null,
        ?int $scheduleId = null
    ): void {
        $this->db->insert(
            'incident_contact',
            [
                'incident_id'     => $incidentId,
                'contact_id'      => $contactId,
                'contactgroup_id' => $contactgroupId,
                'schedule_id'     => $scheduleId,
                'role'            => $role
            ]
        );
    }

    /**
     * Insert a `recipient_role_changed` history row for a single recipient at the given time.
     *
     * Exactly one of $contactId, $contactgroupId or $scheduleId is expected to be set. $timeMs is the
     * stored millisecond timestamp from which the reader derives the recipient's `roleChangedAt`.
     */
    private function seedRoleChange(
        int $incidentId,
        string $newRole,
        int $timeMs,
        ?int $contactId = null,
        ?int $contactgroupId = null,
        ?int $scheduleId = null
    ): void {
        $this->db->insert(
            'incident_history',
            [
                'incident_id'        => $incidentId,
                'type'               => 'recipient_role_changed',
                'new_recipient_role' => $newRole,
                'time'               => $timeMs,
                'contact_id'         => $contactId,
                'contactgroup_id'    => $contactgroupId,
                'schedule_id'        => $scheduleId
            ]
        );
    }

    /**
     * Wrap the seeded incident in the integration object under test.
     *
     * @param int $id
     */
    private function incident(int $id): Incident
    {
        /** @var IncidentModel $model */
        $model = IncidentModel::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();

        return new Incident($model, $this->db);
    }

    /**
     * Collect the given recipients into a list ordered by type and name.
     *
     * The readers do not guarantee an order, so tests asserting more than one recipient normalise it here
     * instead of relying on the database's row order.
     *
     * @param iterable<array{type: string, name: string}> $recipients
     *
     * @return list<array<string, mixed>>
     */
    private function sortedByTypeAndName(iterable $recipients): array
    {
        $list = iterator_to_array($recipients, false);
        usort($list, fn(array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return $list;
    }

    /**
     * Read the incident's subscribers with the `roleChangedAt` timestamp dropped.
     *
     * A write operation stamps the role change with the current time, which cannot be asserted verbatim;
     * the timestamp's contract is covered by the dedicated getSubscribers tests that seed a known time.
     *
     * @return list<array<string, mixed>>
     */
    private function subscribersWithoutTimestamp(Incident $incident): array
    {
        return array_map(
            static function (array $entry): array {
                unset($entry['roleChangedAt']);

                return $entry;
            },
            iterator_to_array($incident->getSubscribers(), false)
        );
    }

    /**
     * Read the stored contact roles as `[['username' => ..., 'role' => ...], ...]`, ordered by username.
     *
     * @return list<array<string, mixed>>
     */
    private function storedContactRoles(): array
    {
        return $this->db->prepexec(
            'SELECT c.username, ic.role FROM incident_contact ic'
            . ' JOIN contact c ON c.id = ic.contact_id ORDER BY c.username'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Read the stored `recipient_role_changed` history as
     * `[['username' => ..., 'old_role' => ..., 'new_role' => ...], ...]`, in insertion order.
     *
     * @return list<array<string, mixed>>
     */
    private function storedRoleHistory(): array
    {
        return $this->db->prepexec(
            'SELECT c.username, h.old_recipient_role AS old_role, h.new_recipient_role AS new_role'
            . ' FROM incident_history h JOIN contact c ON c.id = h.contact_id'
            . ' WHERE h.type = \'recipient_role_changed\' ORDER BY h.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
