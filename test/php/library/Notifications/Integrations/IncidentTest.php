<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Integrations\JobTracker;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\JobQueue;
use Icinga\Module\Notifications\Test\DbTestBackends;
use Icinga\User;
use Icinga\Web\Session\SessionNamespace;
use InvalidArgumentException;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Contract of the integration-facing {@see Incident}: it is identified by usernames (never Contact
 * instances), and every write operation queues a quick action job for the daemon, unless it is a no-op.
 * Applying the role change and recording it in the history is up to the daemon.
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

    /** @var array<string, string> The id tags of the object every incident belongs to */
    private const TAGS = ['host' => 'test-host', 'service' => 'test-service'];

    /** @var Connection The database of the current test, set by every test */
    private Connection $db;

    /**
     * Seed the channel every contact refers to and the object every incident belongs to
     *
     * Neither is seeded per test, as no test changes them. Of the object, the tests only care about its
     * {@see self::TAGS}, which every queued job must carry. This runs before a test's transaction starts,
     * so both survive its rollback.
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

        foreach (self::TAGS as $tag => $value) {
            $db->insert('object_id_tag', [
                'object_id' => self::objectId($db),
                'tag'       => $tag,
                'value'     => $value
            ]);
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerQueuesAManageJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');

        $this->incident($id)->addManager('uname');

        $this->assertSame(
            [['action' => 'manage', 'contact_id' => $contactId, 'object_tags' => self::TAGS]],
            $this->storedJobs()
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
    public function testRemoveManagerQueuesAnUnmanageJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $managerId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $managerId, 'manager');
        $this->seedIncidentContact($id, $this->seedContact('sub'), 'subscriber');

        $this->incident($id)->removeManager();

        $this->assertSame(
            [['action' => 'unmanage', 'contact_id' => $managerId, 'object_tags' => self::TAGS]],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberQueuesASubscribeJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame(
            [['action' => 'subscribe', 'contact_id' => $contactId, 'object_tags' => self::TAGS]],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberQueuesAnUnsubscribeJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $contactId, 'subscriber');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame(
            [['action' => 'unsubscribe', 'contact_id' => $contactId, 'object_tags' => self::TAGS]],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testRoleChangesQueueJobsWhenTheIncidentIsFetchedLazily(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');

        $this->incidentFromQuery($id)->addManager('uname');

        $this->assertSame(
            [['action' => 'manage', 'contact_id' => $contactId, 'object_tags' => self::TAGS]],
            $this->storedJobs(),
            'The lazily fetched incident lacks the id tags of its object'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testARoleChangeIsPendingUntilItsJobIsProcessed(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');
        $session = new SessionNamespace();

        $incident = $this->incident($id, $session);

        $this->assertNull($incident->getPendingAction(), 'A role change is pending before any was requested');

        $incident->addSubscriber('uname');

        $this->assertSame('subscribe', $incident->getPendingAction());
        $this->assertSame(
            'subscribe',
            $this->incident($id, $session)->getPendingAction(),
            'The pending role change is not remembered across requests'
        );

        $this->db->update('job_queue', ['state' => JobQueue::STATE_DONE]);

        $this->assertNull(
            $this->incident($id, $session)->getPendingAction(),
            'A role change is still pending after its job was processed'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testARoleChangeUsesTheGivenUuidAsTheJobsId(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');
        $uuid = Uuid::uuid4();

        $this->incident($id)->addSubscriber('uname', $uuid);

        /** @var JobQueue $job */
        $job = JobQueue::on($this->db)->first();

        $this->assertSame($uuid->toString(), $job->id->toString());
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

        $this->expectException(IncidentNotFoundException::class);

        $this->incidentFromQuery(0)->removeManager();
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
    public function testChainedRoleChangesEachQueueAJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $aliceId = $this->seedContact('alice');
        $bobId = $this->seedContact('bob');

        $this->incident($id)
            ->addManager('alice')
            ->addSubscriber('bob');

        $this->assertSame(
            [
                ['action' => 'manage', 'contact_id' => $aliceId, 'object_tags' => self::TAGS],
                ['action' => 'subscribe', 'contact_id' => $bobId, 'object_tags' => self::TAGS]
            ],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testASecondWriteDoesNotQueueTheEarlierJobAgain(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $aliceId = $this->seedContact('alice');
        $bobId = $this->seedContact('bob');

        $incident = $this->incident($id);
        $incident->addManager('alice');
        $incident->addSubscriber('bob');

        $this->assertSame(
            [
                ['action' => 'manage', 'contact_id' => $aliceId, 'object_tags' => self::TAGS],
                ['action' => 'subscribe', 'contact_id' => $bobId, 'object_tags' => self::TAGS]
            ],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerOfASubscriberQueuesAManageJob(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $contactId = $this->seedContact('uname');
        $this->seedIncidentContact($id, $contactId, 'subscriber');

        $this->incident($id)->addManager('uname');

        $this->assertSame(
            [['action' => 'manage', 'contact_id' => $contactId, 'object_tags' => self::TAGS]],
            $this->storedJobs()
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberDoesNotDemoteAnExistingManager(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerOnAnExistingManagerIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->addManager('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddManagerWithAnotherManagerIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');
        $this->seedIncidentContact($id, $this->seedContact('other'), 'manager');

        $this->incident($id)->addManager('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testAddSubscriberOnAnExistingSubscriberIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->addSubscriber('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerWithOnlySubscribersIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'subscriber');

        $this->incident($id)->removeManager();

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveManagerWithoutRecipientsIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();

        $this->incident($id)->removeManager();

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberOfANonSubscriberIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedIncidentContact($id, $this->seedContact('uname'), 'manager');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    #[DataProvider('sharedDatabases')]
    public function testRemoveSubscriberWithoutAnEntryIsANoop(Connection $db): void
    {
        $this->db = $db;

        $id = $this->seedIncident();
        $this->seedContact('uname');

        $this->incident($id)->removeSubscriber('uname');

        $this->assertSame([], $this->storedJobs(), 'A no-op queued a job');
    }

    /**
     * Wrap the seeded incident in the integration object under test.
     *
     * The incident is loaded along with its object's id tags, as every caller of {@see Incident::fromModel()}
     * does, since a queued job carries them.
     *
     * @param int $id
     * @param SessionNamespace $session Where the instance's {@see JobTracker} remembers jobs, pass the same one
     *     to simulate subsequent requests of the same user
     */
    private function incident(int $id, SessionNamespace $session = new SessionNamespace()): Incident
    {
        /** @var IncidentModel $model */
        $model = IncidentModel::on($this->db)
            ->withColumns('object.id_tags')
            ->filter(Filter::equal('id', $id))
            ->first();

        return Incident::fromModel($model, $this->db, new JobTracker($this->db, $session));
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
        return Incident::fromQuery(
            IncidentModel::on($this->db)->filter(Filter::equal('id', $id)),
            new JobTracker($this->db, new SessionNamespace())
        );
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
     * Read the queued quick action jobs as `[['action' => ..., 'contact_id' => ..., 'object_tags' => ...], ...]`
     *
     * Ordered by contact id and action, as jobs queued within the same millisecond have no reliable order.
     * The object tags are sorted by name, as the database aggregates them in no particular order.
     * Asserts each envelope's version and format, as the daemon would not process the job otherwise.
     *
     * @return list<array{action: string, contact_id: int, object_tags: array<string, string>}>
     */
    private function storedJobs(): array
    {
        $jobs = [];
        foreach (JobQueue::on($this->db) as $job) {
            $envelope = json_decode($job->envelope, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(JobQueue::ENVELOPE_VERSION, $envelope['version']);
            $this->assertSame('quick_action', $envelope['format']);

            $payload = $envelope['payload'];
            ksort($payload['object_tags']);
            $jobs[] = $payload;
        }

        usort(
            $jobs,
            fn(array $a, array $b): int => [$a['contact_id'], $a['action']] <=> [$b['contact_id'], $b['action']]
        );

        return $jobs;
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
