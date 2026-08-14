<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\User;
use InvalidArgumentException;
use ipl\Stdlib\Filter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
 * nullable `username`. Subscribers additionally carry their `role` and the `roleChangedAt` time their
 * current role was last changed; deleted contact groups and schedules are omitted from both.
 *
 * An incident is addressed by the id of the object it belongs to, which is derived from the complete set of
 * id tags its source hands over. Tags are never resolved at query time, so a partial set identifies a
 * different object.
 */
class IncidentTest extends TestCase
{
    /** @var int Millisecond timestamp seeded into `incident_contact.changed_at` when a test does not care about it */
    private const SEEDED_CHANGED_AT = 1700000000000;

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
            . ' object_id BLOB, started_at INTEGER, recovered_at INTEGER, severity VARCHAR, mute_reason VARCHAR,'
            . ' message VARCHAR);'
            . 'CREATE TABLE contact (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name VARCHAR, username VARCHAR,'
            . ' default_channel_id INTEGER, changed_at INTEGER, deleted VARCHAR, external_uuid VARCHAR);'
            . 'CREATE TABLE contactgroup (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, changed_at INTEGER,'
            . ' deleted VARCHAR, external_uuid VARCHAR);'
            . 'CREATE TABLE schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, changed_at INTEGER,'
            . ' timezone VARCHAR, deleted VARCHAR);'
            . 'CREATE TABLE incident_contact (incident_id INTEGER NOT NULL, contact_id INTEGER,'
            . ' contactgroup_id INTEGER, schedule_id INTEGER, role VARCHAR, changed_at INTEGER);'
            . 'CREATE TABLE incident_history (id INTEGER PRIMARY KEY AUTOINCREMENT, incident_id INTEGER NOT NULL,'
            . ' rule_id INTEGER, rule_escalation_id INTEGER, time INTEGER, type VARCHAR, contact_id INTEGER,'
            . ' schedule_id INTEGER, contactgroup_id INTEGER, channel_id INTEGER, new_severity VARCHAR,'
            . ' old_severity VARCHAR, new_recipient_role VARCHAR, old_recipient_role VARCHAR, message VARCHAR,'
            . ' notification_state VARCHAR, sent_at INTEGER);'
        );
    }

    /**
     * The id must be byte for byte what the daemon derives for the same object, as it is the only thing
     * relating an incident to a host or service. This pins it to a value taken from
     * `icinga-notifications/internal/object/object.go::ID()`, so that a change on either side has to be a
     * deliberate one — there is no shared contract the two implementations could be tested against.
     */
    public function testGetObjectIdMatchesTheIdTheDaemonDerives(): void
    {
        $tags = ['host' => 'icinga2', 'environment' => '340a621d95cda5e127c60923df7ebc580dfa4e7d', 'service' => 'http'];

        $this->assertSame(
            strtolower('976404D61D1D19A083031B21CA6784641E85F14C5A55FC1D46C48BD75F5CCADB'),
            $this->objectId($tags)
        );
    }

    /**
     * The daemon hashes the tags in sorted order, so a source may hand them over in any order and still
     * address the same object.
     */
    public function testGetObjectIdIsIndependentOfTheTagOrder(): void
    {
        $tags = ['host' => 'icinga2', 'service' => 'http', 'environment' => 'production'];

        $this->assertSame($this->objectId($tags), $this->objectId(array_reverse($tags, true)));
    }

    /**
     * Objects differing in a single tag must not collide, which is what makes the complete set of id tags
     * a requirement rather than a convention.
     */
    public function testGetObjectIdDiffersPerTagSet(): void
    {
        $this->assertNotSame(
            $this->objectId(['host' => 'icinga2']),
            $this->objectId(['host' => 'icinga2', 'service' => 'http'])
        );
    }

    #[DataProvider('roles')]
    public function testGetRoleReturnsTheUsersRoleInTheIncident(string $role): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('jdoe'), $role);

        $this->assertSame($role, $this->incident($id)->getRole(new User('jdoe')));
    }

    /**
     * Every role an `incident_contact` row may carry, including `recipient` — a configured recipient is not
     * subscribed, and the role has to come back as itself so a consumer offers them to subscribe rather than
     * to unsubscribe.
     *
     * @return array<array{string}>
     */
    public static function roles(): array
    {
        return [['manager'], ['subscriber'], ['recipient']];
    }

    public function testGetRoleReturnsNullForAUserWithoutAnEntry(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('jane'), 'manager');

        $this->assertNull($this->incident($id)->getRole(new User('jdoe')));
    }

    /**
     * The role is scoped to the incident it is read from — an entry the contact holds in another incident
     * must not leak into it, or a consumer would offer the wrong action.
     */
    public function testGetRoleIgnoresEntriesOfOtherIncidents(): void
    {
        $this->seedIncidentContact($this->seedIncident(), $this->seedContact('jdoe'), 'manager');

        $this->assertNull($this->incident($this->seedIncident())->getRole(new User('jdoe')));
    }

    public function testAddManagerAddsTheContactAsManagerByUsername(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $incident = $this->incident($id);
        $incident->addManager('uname');

        $this->assertSame(
            [['name' => 'Uname Example', 'username' => 'uname', 'role' => 'manager']],
            $this->withoutRoleChangedAt($incident->getSubscribers())
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
            [['name' => 'Uname Example', 'username' => 'uname', 'role' => 'subscriber']],
            $this->withoutRoleChangedAt($incident->getSubscribers())
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
            [['name' => 'Uname Example', 'username' => 'uname', 'role' => 'subscriber']],
            $this->withoutRoleChangedAt($incident->getSubscribers())
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

    public function testGetSubscribersExcludesConfiguredRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'recipient');

        $this->assertSame(
            [['name' => 'Alice Example', 'username' => 'alice', 'role' => 'manager']],
            $this->withoutRoleChangedAt($this->incident($id)->getSubscribers())
        );
    }

    public function testGetSubscribersIgnoresRowsWithoutARecipientReference(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        // Neither contact, contact group nor schedule is referenced; such a row yields nothing.
        $this->seedIncidentContact($id, null, 'subscriber');

        $this->assertSame(
            [['name' => 'Alice Example', 'username' => 'alice', 'role' => 'manager']],
            $this->withoutRoleChangedAt($this->incident($id)->getSubscribers())
        );
    }

    public function testGetSubscribersOmitsDeletedRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('gone-contact', deleted: true), 'subscriber');
        $this->seedIncidentContact(
            $id,
            null,
            'subscriber',
            contactgroupId: $this->seedContactgroup('gone-group', deleted: true)
        );
        $this->seedIncidentContact(
            $id,
            null,
            'subscriber',
            scheduleId: $this->seedSchedule('gone-schedule', deleted: true)
        );

        $this->assertSame(
            [['name' => 'Alice Example', 'username' => 'alice', 'role' => 'subscriber']],
            $this->withoutRoleChangedAt($this->incident($id)->getSubscribers())
        );
    }

    public function testGetSubscribersResolvesRoleChangedAtFromTheContactsChangedAt(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $this->seedIncidentContact($id, $alice, 'manager', changedAt: 1700000000000);

        $subscribers = iterator_to_array($this->incident($id)->getSubscribers(), false);

        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(DateTime::class, $subscribers[0]['roleChangedAt']);
        $this->assertSame(1700000000, $subscribers[0]['roleChangedAt']->getTimestamp());

        unset($subscribers[0]['roleChangedAt']);
        $this->assertSame(
            ['name' => 'Alice Example', 'username' => 'alice', 'role' => 'manager'],
            $subscribers[0],
            'Apart from roleChangedAt the entry carries the uniform recipient shape'
        );
    }

    public function testGetRecipientsYieldsConfiguredRecipientsOfEachType(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'recipient');
        $this->seedIncidentContact($id, null, 'recipient', contactgroupId: $this->seedContactgroup('windows-admins'));
        $this->seedIncidentContact($id, null, 'recipient', scheduleId: $this->seedSchedule('On-Call'));

        $this->assertSame(
            [
                ['type' => 'contact', 'name' => 'Alice Example', 'username' => 'alice'],
                ['type' => 'contactgroup', 'name' => 'windows-admins', 'username' => null],
                ['type' => 'schedule', 'name' => 'On-Call', 'username' => null],
            ],
            $this->withoutRoleChangedAt($this->sortedByTypeAndName($this->incident($id)->getRecipients()))
        );
    }

    public function testGetRecipientsExcludesActiveSubscribers(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('carol'), 'recipient');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'Carol Example', 'username' => 'carol']],
            $this->withoutRoleChangedAt($this->incident($id)->getRecipients())
        );
    }

    public function testGetRecipientsOmitsDeletedRecipients(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'recipient');
        $this->seedIncidentContact($id, $this->seedContact('gone-contact', deleted: true), 'recipient');
        $this->seedIncidentContact(
            $id,
            null,
            'recipient',
            contactgroupId: $this->seedContactgroup('gone-group', deleted: true)
        );
        $this->seedIncidentContact(
            $id,
            null,
            'recipient',
            scheduleId: $this->seedSchedule('gone-schedule', deleted: true)
        );

        $this->assertSame(
            [['type' => 'contact', 'name' => 'Alice Example', 'username' => 'alice']],
            $this->withoutRoleChangedAt($this->incident($id)->getRecipients())
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
     * Derive the object id the given tags identify.
     *
     * The derivation is not public, as it is an implementation detail of how an incident is looked up, and
     * it is handed a fixed source id because resolving the real one would need the configured database.
     *
     * @param array<string, string> $tags
     */
    private function objectId(array $tags): string
    {
        //TODO: Drop the source id argument once it is no longer used to generate object ids
        /** @var string $objectId */
        $objectId = (new ReflectionMethod(Incident::class, 'getObjectId'))->invoke(null, $tags, 5);

        return $objectId;
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
     * "alice"), so the readers' name/username pairing can be asserted unambiguously.
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
        ?int $scheduleId = null,
        int $changedAt = self::SEEDED_CHANGED_AT
    ): void {
        $this->db->insert(
            'incident_contact',
            [
                'incident_id'     => $incidentId,
                'contact_id'      => $contactId,
                'contactgroup_id' => $contactgroupId,
                'schedule_id'     => $scheduleId,
                'role'            => $role,
                'changed_at'      => $changedAt
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
     * @param iterable<array{type?: string, name: string}> $recipients
     *
     * @return list<array<string, mixed>>
     */
    private function sortedByTypeAndName(iterable $recipients): array
    {
        $list = iterator_to_array($recipients, false);
        usort($list, fn(array $a, array $b): int => [$a['type'] ?? '', $a['name']] <=> [$b['type'] ?? '', $b['name']]);

        return $list;
    }

    /**
     * Collect the given recipients into a list with the `roleChangedAt` timestamp dropped.
     *
     * The timestamp cannot be asserted verbatim — a write stamps the role change with the current time, and a
     * seeded one yields a {@see DateTime} that is never `assertSame`-equal — so tests not focused on it drop it
     * here. Its contract is covered by the dedicated test that seeds a known time.
     *
     * @param iterable<array<string, mixed>> $recipients
     *
     * @return list<array<string, mixed>>
     */
    private function withoutRoleChangedAt(iterable $recipients): array
    {
        return array_map(
            function (array $entry): array {
                $this->assertArrayHasKey('roleChangedAt', $entry);
                $this->assertInstanceOf(DateTime::class, $entry['roleChangedAt']);
                unset($entry['roleChangedAt']);

                return $entry;
            },
            iterator_to_array($recipients, false)
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
