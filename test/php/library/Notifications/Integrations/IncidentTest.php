<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Test\DbTestBackends;
use Icinga\User;
use InvalidArgumentException;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
 * The incident itself is either handed over as a model or resolved lazily from a query, which is why the
 * role tests run against both {@see Incident::fromModel()} and {@see Incident::fromQuery()} — each resolves
 * a role by different means. Only the latter can fail to find an incident, and if it does, every operation
 * reports it the same way, by throwing an {@see IncidentNotFoundException}.
 *
 * Every test runs against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`), each within its own transaction which is rolled back afterwards. The
 * rows an incident consists of are seeded by the test itself, everything it merely requires to exist by
 * {@see self::initializeNotificationsDb()}.
 */
#[TransactionIsolation]
class IncidentTest extends TestCase
{
    use DbTestBackends;

    /** @var int Id of the channel every contact refers to */
    private const CHANNEL_ID = 1;

    /** @var int Millisecond timestamp every seeded `incident_contact` row is stamped with */
    private const ROLE_CHANGED_AT = 1700000000000;

    /** @var Connection The database of the current test, set by every test */
    private Connection $db;

    /**
     * Seed the channel every contact refers to and the object every incident belongs to
     *
     * Neither is seeded per test, as no test changes them and none of them cares about the object beyond that
     * it exists. This runs before a test's transaction starts, so both survive its rollback.
     */
    protected static function initializeNotificationsDb(Connection $db): void
    {
        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('channel', [
            'id'            => self::CHANNEL_ID,
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000c1'),
            'name'          => 'Test',
            'type'          => 'email',
            'changed_at'    => (int) (new DateTime())->format('Uv')
        ]);

        $db->insert('object', [
            'id'   => self::objectId($db),
            'name' => 'test'
        ]);
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerAddsTheContactAsManagerByUsername(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testAddManagerThrowsForAnUnknownUsername(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();

        $this->expectException(InvalidArgumentException::class);

        $this->incident($id)->addManager('ghost');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerDemotesTheManagerToSubscriber(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberAddsTheContactAsSubscriberByUsername(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberDeletesTheSubscriberEntry(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testGetSubscribersExcludesConfiguredRecipients(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'recipient');

        $this->assertSame(
            [['name' => 'Alice Example', 'username' => 'alice', 'role' => 'manager']],
            $this->withoutRoleChangedAt($this->incident($id)->getSubscribers())
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testGetSubscribersOmitsDeletedRecipients(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testGetSubscribersResolvesRoleChangedAtFromTheRecipientsChangedAt(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');

        $subscribers = iterator_to_array($this->incident($id)->getSubscribers(), false);

        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(DateTime::class, $subscribers[0]['roleChangedAt']);
        $this->assertSame(intdiv(self::ROLE_CHANGED_AT, 1000), $subscribers[0]['roleChangedAt']->getTimestamp());

        unset($subscribers[0]['roleChangedAt']);
        $this->assertSame(
            ['name' => 'Alice Example', 'username' => 'alice', 'role' => 'manager'],
            $subscribers[0],
            'Apart from roleChangedAt the entry carries the uniform recipient shape'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRecipientsYieldsConfiguredRecipientsOfEachType(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'recipient');
        $this->seedIncidentContact($id, null, 'recipient', contactgroupId: $this->seedContactgroup('windows-admins'));
        $this->seedIncidentContact($id, null, 'recipient', scheduleId: $this->seedSchedule('On-Call'));

        $recipients = $this->withoutRoleChangedAt($this->incident($id)->getRecipients());

        // The reader does not guarantee an order, so it is normalised here instead of relying on the row order
        usort($recipients, fn(array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        $this->assertSame(
            [
                ['type' => 'contact', 'name' => 'Alice Example', 'username' => 'alice'],
                ['type' => 'contactgroup', 'name' => 'windows-admins', 'username' => null],
                ['type' => 'schedule', 'name' => 'On-Call', 'username' => null],
            ],
            $recipients
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRecipientsExcludesActiveSubscribers(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('alice'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('bob'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('carol'), 'recipient');

        $this->assertSame(
            [['type' => 'contact', 'name' => 'Carol Example', 'username' => 'carol']],
            $this->withoutRoleChangedAt($this->incident($id)->getRecipients())
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRecipientsOmitsDeletedRecipients(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testIsMutedReflectsTheMuteReason(Connection $db): void
    {
        $this->db = $db;

        $muted = $this->seedIncident(muteReason: 'down for maintenance');
        $notMuted = $this->seedIncident();

        $this->assertTrue($this->incident($muted)->isMuted());
        $this->assertFalse($this->incident($notMuted)->isMuted());
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleReturnsTheContactsRole(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('boss'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('sub'), 'subscriber');
        $this->seedIncidentContact($id, $this->seedContact('rcpt'), 'recipient');

        $incident = $this->incident($id);

        $this->assertSame('manager', $incident->getRole(new User('boss')));
        $this->assertSame('subscriber', $incident->getRole(new User('sub')));
        $this->assertSame('recipient', $incident->getRole(new User('rcpt')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleReturnsNullForAContactWithoutARole(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->assertNull($this->incident($id)->getRole(new User('uname')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleReturnsNullForAnUnknownUsername(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();

        $this->assertNull($this->incident($id)->getRole(new User('ghost')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleOmitsDeletedContacts(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname', deleted: true), 'manager');

        $this->assertNull($this->incident($id)->getRole(new User('uname')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleIgnoresRolesOfOtherIncidents(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $otherId = $this->seedIncident();
        $this->seedIncidentContact($otherId, $this->seedContact('uname'), 'manager');

        $this->assertNull($this->incident($id)->getRole(new User('uname')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleReturnsTheContactsRoleWhenTheIncidentIsFetchedLazily(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('boss'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('sub'), 'subscriber');
        $this->seedContact('nobody');

        $incident = $this->incidentFromQuery($id);

        $this->assertSame('manager', $incident->getRole(new User('boss')));
        $this->assertSame('subscriber', $incident->getRole(new User('sub')));
        $this->assertNull($incident->getRole(new User('nobody')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleOmitsDeletedContactsWhenTheIncidentIsFetchedLazily(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname', deleted: true), 'manager');

        $this->assertNull($this->incidentFromQuery($id)->getRole(new User('uname')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleIgnoresRolesOfOtherIncidentsWhenTheIncidentIsFetchedLazily(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $otherId = $this->seedIncident();
        $this->seedIncidentContact($otherId, $this->seedContact('uname'), 'manager');

        $this->assertNull($this->incidentFromQuery($id)->getRole(new User('uname')));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->getRole(new User('uname'));
    }

    #[DataProvider('sharedDatabases')]
    public function testIsMutedThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->isMuted();
    }

    #[DataProvider('sharedDatabases')]
    public function testGetSubscribersThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->getSubscribers();
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRecipientsThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->getRecipients();
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->seedContact('uname'); // Or the username is reported as unknown instead

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->addManager('uname');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->seedContact('uname');

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->addSubscriber('uname');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->seedContact('uname');

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->removeManager('uname');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberThrowsWithoutAMatchingIncident(Connection $db): void
    {
        $this->db = $db;

        $this->seedContact('uname');

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->removeSubscriber('uname');
    }

    #[DataProvider('sharedDatabases')]
    public function testTheIncidentIsFetchedLazilyAndOnlyOnce(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $incident = $this->incidentFromQuery($id);

        // Had the instance fetched the incident upon creation, it would still answer with the row as it was then
        $this->db->update('incident', ['mute_reason' => 'down for maintenance'], ['id = ?' => $id]);

        $this->assertTrue($incident->isMuted(), 'The incident was fetched before it was used');

        // And now that it has been fetched, that very row is what it keeps answering with
        $this->db->update('incident', ['mute_reason' => null], ['id = ?' => $id]);

        $this->assertTrue($incident->isMuted(), 'The incident was fetched again instead of being reused');
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRoleFetchesTheIncidentAlongWithTheRoleAndOnlyOnce(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('boss'), 'manager');
        $this->seedIncidentContact($id, $this->seedContact('sub'), 'subscriber');

        $incident = $this->incidentFromQuery($id);

        $this->assertSame('manager', $incident->getRole(new User('boss')));

        $this->db->update('incident', ['mute_reason' => 'down for maintenance'], ['id = ?' => $id]);

        // Only the first call fetches the incident, the role of any other user is resolved by a separate query
        $this->assertSame('subscriber', $incident->getRole(new User('sub')), 'The second role was not resolved');
        $this->assertFalse($incident->isMuted(), 'The incident was fetched again instead of being reused');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerWritesRoleChangedHistory(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->addManager('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'manager']],
            $this->storedRoleHistory()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerWritesRoleChangedHistory(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeManager('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'manager', 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberWritesRoleChangedHistory(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => null, 'new_role' => 'subscriber']],
            $this->storedRoleHistory()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberWritesRoleChangedHistory(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'subscriber', 'new_role' => null]],
            $this->storedRoleHistory()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testChainedRoleChangesEachWriteAHistoryRow(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testASecondWriteDoesNotDuplicateHistoryFromAnEarlierWrite(Connection $db): void
    {
        $this->db = $db;

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

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberDoesNotDemoteAnExistingManager(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerPromotesAnExistingSubscriberInPlace(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addManager('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame(
            [['username' => 'uname', 'old_role' => 'subscriber', 'new_role' => 'manager']],
            $this->storedRoleHistory()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerOnAnExistingManagerIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addManager('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberOnAnExistingSubscriberIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory(), 'A no-op records no role change');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerOfANonManagerIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeManager('uname');

        // A subscriber must not be demoted by removeManager, and no history is written.
        $this->assertSame([['username' => 'uname', 'role' => 'subscriber']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerWithoutAnEntryIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeManager('uname');

        $this->assertSame([], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberOfANonSubscriberIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeSubscriber('uname');

        // A manager entry must not be deleted by removeSubscriber.
        $this->assertSame([['username' => 'uname', 'role' => 'manager']], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberWithoutAnEntryIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame([], $this->storedContactRoles());
        $this->assertSame([], $this->storedRoleHistory());
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

        return Incident::fromModel($model, $this->db);
    }

    /**
     * Wrap the incident with the given id in an instance that resolves it lazily.
     *
     * The incident does not have to exist, which is how the tests reach the missing incident cases.
     *
     * @param int $id
     */
    private function incidentFromQuery(int $id): Incident
    {
        return Incident::fromQuery(IncidentModel::on($this->db)->filter(Filter::equal('id', $id)));
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

    /**
     * Insert an open incident and return its generated id
     *
     * @param ?string $muteReason The reason the incident is muted, null leaves it unmuted
     */
    private function seedIncident(?string $muteReason = null): int
    {
        $this->db->insert('incident', [
            'object_id'   => self::objectId($this->db),
            'severity'    => 'crit',
            'started_at'  => (int) (new DateTime())->format('Uv'),
            'mute_reason' => $muteReason
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a contact with the given username and return its generated id
     *
     * The full name is derived from the username (e.g. "Alice Example" for "alice"), so the readers'
     * name/username pairing can be asserted unambiguously.
     */
    private function seedContact(string $username, bool $deleted = false): int
    {
        $this->db->insert('contact', [
            'external_uuid' => static::transformUUIDForDB(
                $this->db,
                sprintf('00000000-0000-0000-0000-%012x', crc32($username))
            ),
            'full_name'          => ucfirst($username) . ' Example',
            'username'           => $username,
            'default_channel_id' => self::CHANNEL_ID,
            'changed_at'         => (int) (new DateTime())->format('Uv'),
            'deleted'            => $deleted ? 'y' : 'n'
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a contact group with the given name and return its generated id
     */
    private function seedContactgroup(string $name, bool $deleted = false): int
    {
        $this->db->insert('contactgroup', [
            'external_uuid' => static::transformUUIDForDB(
                $this->db,
                sprintf('00000000-0000-0000-0001-%012x', crc32($name))
            ),
            'name'          => $name,
            'changed_at'    => (int) (new DateTime())->format('Uv'),
            'deleted'       => $deleted ? 'y' : 'n'
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a schedule with the given name and return its generated id
     */
    private function seedSchedule(string $name, bool $deleted = false): int
    {
        $this->db->insert('schedule', [
            'name'       => $name,
            'timezone'   => 'Europe/Berlin',
            'changed_at' => (int) (new DateTime())->format('Uv'),
            'deleted'    => $deleted ? 'y' : 'n'
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert an `incident_contact` row referencing exactly one recipient
     *
     * Exactly one of $contactId, $contactgroupId or $scheduleId is expected to be set; the others stay
     * null, mirroring the polymorphic recipient key the daemon writes. The database enforces this.
     *
     * @param string $role One of `recipient`, `subscriber` or `manager`
     */
    private function seedIncidentContact(
        int $incidentId,
        ?int $contactId,
        string $role,
        ?int $contactgroupId = null,
        ?int $scheduleId = null
    ): void {
        $this->db->insert('incident_contact', [
            'incident_id'     => $incidentId,
            'contact_id'      => $contactId,
            'contactgroup_id' => $contactgroupId,
            'schedule_id'     => $scheduleId,
            'role'            => $role,
            'changed_at'      => self::ROLE_CHANGED_AT
        ]);
    }

    /**
     * Get the id of the object every incident belongs to
     *
     * These tests don't care about the object, they only require one to exist, hence its fixed id. It is
     * returned in the representation the current database expects for a binary literal, as the tests seed
     * the tables directly, i.e. without the ORM's Binary behavior in between.
     */
    private static function objectId(Connection $db): string
    {
        $id = str_repeat('7e', 32); // The column requires a SHA256, i.e. exactly 32 bytes

        return $db->getAdapter() instanceof Pgsql ? "\\x$id" : hex2bin($id);
    }
}
