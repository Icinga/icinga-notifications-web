<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use InvalidArgumentException;
use ipl\Stdlib\Filter;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;

/**
 * Contract of the integration-facing {@see Incident}: it is identified by usernames (never Contact
 * instances), interacts with the model and persists internally, and yields the username and full name
 * of contacts from its readers.
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
            . 'CREATE TABLE incident_contact (incident_id INTEGER NOT NULL, contact_id INTEGER, role VARCHAR);'
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
            [['username' => 'uname', 'full_name' => 'Uname Example']],
            iterator_to_array($incident->getManagers(), false)
        );
        $this->assertSame([], $this->storedContactRoles(), 'Nothing is persisted before save()');

        $incident->save();

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
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

        $this->assertSame([], iterator_to_array($incident->getManagers(), false));
        $this->assertSame(
            [['username' => 'uname', 'full_name' => 'Uname Example']],
            iterator_to_array($incident->getSubscribers(), false)
        );
        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());

        $incident->save();

        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
    }

    public function testAddSubscriberAddsTheContactAsSubscriberByUsername(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $incident = $this->incident($id);
        $incident->addSubscriber('uname');

        $this->assertSame(
            [['username' => 'uname', 'full_name' => 'Uname Example']],
            iterator_to_array($incident->getSubscribers(), false)
        );
        $this->assertSame([], $this->storedContactRoles(), 'Nothing is persisted before save()');

        $incident->save();

        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
    }

    public function testRemoveSubscriberDeletesTheSubscriberEntry(): void
    {
        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $contactId, 'subscriber');

        $incident = $this->incident($id);
        $incident->removeSubscriber('uname');

        $this->assertSame([], iterator_to_array($incident->getSubscribers(), false));
        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());

        $incident->save();

        $this->assertSame([], $this->storedContactRoles());
    }

    public function testGetManagersYieldsTheUsernameAndFullNameOfManagers(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('carol'), 'subscriber');

        $managers = iterator_to_array($this->incident($id)->getManagers(), false);
        sort($managers);

        $this->assertSame(
            [
                ['username' => 'alice', 'full_name' => 'Alice Example'],
                ['username' => 'bob', 'full_name' => 'Bob Example'],
            ],
            $managers
        );
    }

    public function testGetManagersExcludesRecipientsThatAreNotContacts(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, null, 'manager');

        $this->assertSame(
            [['username' => 'alice', 'full_name' => 'Alice Example']],
            iterator_to_array($this->incident($id)->getManagers(), false)
        );
    }

    public function testGetSubscribersYieldsTheUsernameAndFullNameOfSubscribers(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'manager');

        $this->assertSame(
            [['username' => 'alice', 'full_name' => 'Alice Example']],
            iterator_to_array($this->incident($id)->getSubscribers(), false)
        );
    }

    public function testGetNotifiedContactsYieldsTheUsernameAndFullNameOfDistinctContacts(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $bob = $this->seedContact('bob');

        $this->seedHistory($id, $alice, 'notified');
        $this->seedHistory($id, $alice, 'notified');
        $this->seedHistory($id, $bob, 'notified');
        $this->seedHistory($id, $alice, 'opened');

        $notified = iterator_to_array($this->incident($id)->getNotifiedContacts(), false);
        sort($notified);

        $this->assertSame(
            [
                ['username' => 'alice', 'full_name' => 'Alice Example'],
                ['username' => 'bob', 'full_name' => 'Bob Example'],
            ],
            $notified
        );
    }

    public function testGetNotifiedContactsReadsTheDatabaseRegardlessOfInMemoryMutations(): void
    {
        $id = $this->seedIncident();
        $alice = $this->seedContact('alice');
        $this->seedContact('uname');
        $this->seedHistory($id, $alice, 'notified');

        $incident = $this->incident($id);
        $incident->addManager('uname');

        $this->assertSame(
            [['username' => 'alice', 'full_name' => 'Alice Example']],
            iterator_to_array($incident->getNotifiedContacts(), false)
        );
        $incident->save();
        $this->assertSame(
            [['username' => 'alice', 'full_name' => 'Alice Example']],
            iterator_to_array($incident->getNotifiedContacts(), false)
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

        $this->incident($id)->addManager('uname')->save();

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'manager']],
            $this->storedRoleHistory()
        );
    }

    public function testRemoveManagerWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeManager('uname')->save();

        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'manager', 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    public function testAddSubscriberWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->addSubscriber('uname')->save();

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    public function testRemoveSubscriberWritesRoleChangedHistory(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeSubscriber('uname')->save();

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
            ->addManager('bob')
            ->save();

        $this->assertSame(
            [
                ['username' => 'alice', 'old_role' => null, 'new_role' => 'manager'],
                ['username' => 'bob', 'old_role' => null, 'new_role' => 'manager'],
            ],
            $this->storedRoleHistory()
        );
    }

    public function testAddSubscriberDoesNotDemoteAnExistingManager(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addSubscriber('uname')->save();

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testAddManagerPromotesAnExistingSubscriberInPlace(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addManager('uname')->save();

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

        $this->incident($id)->addManager('uname')->save();

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    public function testAddSubscriberOnAnExistingSubscriberIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addSubscriber('uname')->save();

        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    public function testRemoveManagerOfANonManagerIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeManager('uname')->save();

        // A subscriber must not be demoted by removeManager, and no history is written.
        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveManagerWithoutAnEntryIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeManager('uname')->save();

        $this->assertSame([], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveSubscriberOfANonSubscriberIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeSubscriber('uname')->save();

        // A manager entry must not be deleted by removeSubscriber.
        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    public function testRemoveSubscriberWithoutAnEntryIsANoop(): void
    {
        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeSubscriber('uname')->save();

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
    private function seedContact(string $username): int
    {
        $fullName ??= ucfirst($username) . ' Example';

        $this->db->insert('contact', ['full_name' => $fullName, 'username' => $username, 'deleted' => 'n']);

        return (int) $this->db->lastInsertId();
    }

    private function seedIncidentContact(int $incidentId, ?int $contactId, string $role): void
    {
        $this->db->insert(
            'incident_contact',
            ['incident_id' => $incidentId, 'contact_id' => $contactId, 'role' => $role]
        );
    }

    private function seedHistory(int $incidentId, int $contactId, string $type): void
    {
        $this->db->insert(
            'incident_history',
            ['incident_id' => $incidentId, 'contact_id' => $contactId, 'type' => $type, 'time' => 0]
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
