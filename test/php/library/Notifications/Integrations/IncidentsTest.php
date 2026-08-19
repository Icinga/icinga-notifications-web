<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Integrations\Incidents;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Test\DbTestBackends;
use Icinga\User;
use ipl\Orm\Query;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Sql\Test\TestConnection;
use ipl\Stdlib\Filter\All;
use ipl\Stdlib\Filter\Any;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\Rule;
use ipl\Stdlib\Filter\Unlike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for {@see Incidents}.
 *
 * The tests that execute a query run against real databases — once for MySQL and once for PostgreSQL (see
 * {@see DbTestBackends} / `#[DataProvider('sharedDatabases')]`). Each of them runs inside its own transaction which is
 * rolled back afterwards, so the incidents, objects and sources it seeds don't leak into the next test. The
 * prerequisite channel a contact requires is seeded once per driver in {@see self::initializeNotificationsDb()} and
 * its id captured into {@see self::$channelId} (the id can't be assumed as rolled-back transactions still advance
 * the auto-increment).
 *
 * The tests that only assert on the filter {@see Incidents} constructs don't need a database at all and use a
 * {@see TestConnection} instead.
 */
#[TransactionIsolation]
class IncidentsTest extends TestCase
{
    use DbTestBackends;

    /** @var int Id of the channel seeded per driver (auto-increment drifts across rolled-back transactions) */
    private static int $channelId;

    /**
     * Object ids seeded into the current test's database, keyed by source id and object id
     *
     * @var array<int, array<string, true>>
     */
    private array $seededObjects = [];

    /** @var Connection The database of the current test, set by every test using the shared databases */
    private Connection $db;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('channel', [
            'external_uuid' => '00000000-0000-0000-0000-0000000000c1',
            'name'          => 'Test',
            'type'          => 'email',
            'changed_at'    => (int) (new DateTime())->format('Uv')
        ]);

        self::$channelId = (int) $db->lastInsertId();
    }

    /**
     * Reset the set of seeded object ids so each test starts with a fresh, empty database
     */
    protected function setUp(): void
    {
        $this->seededObjects = [];

        // The trait's own setUp() is not used as this class defines one, so the previous test's transaction
        // has to be rolled back here
        $this->rollbackChanges();
    }

    public function testBuildsAnEqualFilterForEachTagWithAValue(): void
    {
        $conditions = $this->conditions($this->builtFilter(['host' => 'icinga2', 'service' => 'http']));

        $this->assertCount(2, $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.host', 'icinga2'], $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.service', 'http'], $conditions);
    }

    public function testBuildsAnAbsenceFilterForTagsGivenAsNull(): void
    {
        $conditions = $this->conditions($this->builtFilter(['host' => 'icinga2', 'service' => null]));

        $this->assertCount(2, $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.host', 'icinga2'], $conditions);
        // A null value requires the tag's absence, expressed as "no value matches the wildcard".
        $this->assertContains([Unlike::class, 'incident.object.tag.service', '*'], $conditions);
    }

    public function testBuildsNoConditionsForATagSetWithoutTags(): void
    {
        // A tag set without tags constrains nothing, the query is still limited to open incidents though,
        // which {@see self::conditions()} asserts.
        $this->assertSame([], $this->conditions($this->builtFilter([])));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetIterator(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b']);

        $incidents = iterator_to_array(new Incidents([[]], $db, true), false);

        $this->assertCount(2, $incidents);
        foreach ($incidents as $incident) {
            $this->assertInstanceOf(Incident::class, $incident);
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testHasIncident(Connection $db): void
    {
        $this->db = $db;

        $this->assertFalse((new Incidents([[]], $db, true))->hasIncident());

        $this->seedIncident(1, ['host' => 'a']);

        $this->assertTrue((new Incidents([[]], $db, true))->hasIncident());
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRolesYieldsTheUsersRoleForEachIncidentTheyAreInvolvedIn(Connection $db): void
    {
        $this->db = $db;

        $managed = $this->seedIncident(1, ['host' => 'a']);
        $subscribed = $this->seedIncident(2, ['host' => 'b']);
        $foreign = $this->seedIncident(3, ['host' => 'c']);

        $jdoe = $this->seedContact('jdoe');
        $this->seedRole($managed, $jdoe, 'manager');
        $this->seedRole($subscribed, $jdoe, 'subscriber');
        $this->seedRole($foreign, $this->seedContact('jane'), 'recipient');

        $roles = [];
        foreach ((new Incidents([[]], $db, true))->getRoles(new User('jdoe')) as $incident => $role) {
            $roles[$this->incidentId($incident)] = $role;
        }

        ksort($roles);

        $expected = [$managed => 'manager', $subscribed => 'subscriber'];
        ksort($expected);

        $this->assertSame($expected, $roles, 'getRoles() did not yield the user\'s role for each of their incidents');
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRolesIgnoresClosedIncidents(Connection $db): void
    {
        $this->db = $db;

        $recovered = $this->seedIncident(1, ['host' => 'a'], 1_700_000_000_000);
        $this->seedRole($recovered, $this->seedContact('jdoe'), 'manager');

        $this->assertSame([], iterator_to_array((new Incidents([[]], $db, true))->getRoles(new User('jdoe'))));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRolesYieldsNothingForAUserWithoutIncidents(Connection $db): void
    {
        $this->db = $db;

        $this->seedRole($this->seedIncident(1, ['host' => 'a']), $this->seedContact('jane'), 'manager');

        $this->assertSame([], iterator_to_array((new Incidents([[]], $db, true))->getRoles(new User('jdoe'))));
    }

    #[DataProvider('sharedDatabases')]
    public function testGetRolesYieldsOnlyTheRoleForTheGivenUser(Connection $db): void
    {
        $this->db = $db;

        $incidentId = $this->seedIncident(1, ['host' => 'a']);
        $this->seedRole($incidentId, $this->seedContact('jane'), 'manager');
        $this->seedRole($incidentId, $this->seedContact('jdoe'), 'subscriber');
        $this->seedRole($incidentId, $this->seedContact('bob'), 'recipient');

        $roles = [];
        foreach ((new Incidents([['host' => 'a']], $db, true))->getRoles(new User('jdoe')) as $incident => $role) {
            $roles[] = [$this->incidentId($incident), $role];
        }

        $this->assertSame([[$incidentId, 'subscriber']], $roles);
    }

    #[DataProvider('sharedDatabases')]
    public function testIteratingAfterHasIncidentYieldsAllMatchesFromTheSameInstance(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b']);

        $incidents = new Incidents([[]], $db, true);

        $this->assertTrue($incidents->hasIncident());
        $this->assertCount(2, iterator_to_array($incidents, false));
    }

    #[DataProvider('sharedDatabases')]
    public function testCount(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(1, ['host' => 'a']);

        $this->assertEquals(1, (new Incidents([[]], $db, true))->count());
    }

    #[DataProvider('sharedDatabases')]
    public function testExcludesClosedIncidents(Connection $db): void
    {
        $this->db = $db;

        // A recovered (closed) incident must never be yielded — the integration only deals with open
        // incidents (recovered_at IS NULL).
        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b'], 1_700_000_000_000);

        $incidents = new Incidents([[]], $db, true);

        $this->assertEquals(1, $incidents->count());
        $this->assertCount(1, iterator_to_array($incidents, false));
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentsOfObjectsCarryingAllGivenTags(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(1, ['host' => 'a']);
        $service = $this->seedIncident(1, ['host' => 'a', 'service' => 'http']);
        $this->seedIncident(1, ['host' => 'b']);

        // The host's incident and the one of its service, matched by the tag they have in common
        $this->assertSame(
            [$host, $service],
            $this->incidentIds(new Incidents([['host' => 'a']], $db, true)),
            'Not all incidents of the objects carrying the given tag were matched'
        );

        // Only the host's incident, as a null value requires the tag's absence
        $this->assertSame(
            [$host],
            $this->incidentIds(new Incidents([['host' => 'a', 'service' => null]], $db, true)),
            'A tag given as null did not exclude the objects carrying it'
        );

        // Only the service's incident, as its object is the only one carrying both tags
        $this->assertSame(
            [$service],
            $this->incidentIds(new Incidents([['host' => 'a', 'service' => 'http']], $db, true)),
            'Not only the incidents of the object carrying all given tags were matched'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentOfTheObjectCarryingExactlyTheGivenTags(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(1, ['host' => 'a']);
        $service = $this->seedIncident(1, ['host' => 'a', 'service' => 'http']);

        $this->assertSame(
            [$host],
            $this->incidentIds(new Incidents([['host' => 'a']], $db, false)),
            "The incident of the object carrying exactly the host's tags was not matched"
        );

        $this->assertSame(
            [$service],
            $this->incidentIds(new Incidents([['host' => 'a', 'service' => 'http']], $db, false)),
            "The incident of the object carrying exactly the service's tags was not matched"
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesNoIncidentOfObjectsCarryingTagsBesidesTheGivenOnes(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(1, ['host' => 'a', 'service' => 'http']);

        $this->assertSame(
            [],
            $this->incidentIds(new Incidents([['host' => 'a']], $db, false)),
            'The incident of an object carrying tags besides the given ones was matched'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentsOfObjectsSatisfyingAnyGivenTagSet(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(1, ['host' => 'a']);
        $service = $this->seedIncident(1, ['host' => 'b', 'service' => 'http']);
        $this->seedIncident(1, ['host' => 'c']);

        // An object only has to satisfy any of the tag sets, each of which identifies it completely
        $this->assertSame(
            [$host, $service],
            $this->incidentIds(new Incidents([['host' => 'a'], ['host' => 'b', 'service' => 'http']], $db, false)),
            'Not the incidents of the objects satisfying any of the given tag sets were matched'
        );
    }

    /**
     * Build the query {@see Incidents} would run for the given tags and return its filter
     *
     * The query is never executed. Asserting on the filter the integration constructs is this unit's
     * responsibility, turning it into SQL is ipl-orm's, which is why a connection-less test database is
     * sufficient here.
     *
     * @param array<string, ?string> $tags
     */
    private function builtFilter(array $tags): Chain
    {
        $incidents = new Incidents([$tags], new TestConnection(), true);

        /** @var Query $query */
        $query = (new ReflectionMethod($incidents, 'buildQuery'))->invoke($incidents);

        return $query->getFilter();
    }

    /**
     * Get the conditions of the tag set filters of the given query filter
     *
     * Asserts that the filter is an `All` of the recovered_at condition and an `Any` with one `All` per tag set,
     * then returns the conditions of those sets as [operator class, column, value] triples for order-independent
     * assertions. The recovered_at condition is not among them, it belongs to no tag set and is asserted here.
     *
     * Note that a filter built without any tag set has a different shape, as it matches no incidents at all,
     * such has to be asserted on the filter itself.
     *
     * @param int $tagSets The number of tag sets the filter is expected to consist of
     *
     * @return list<array{class-string, string|array<string>, mixed}>
     */
    private function conditions(Chain $filter, int $tagSets = 1): array
    {
        $rules = iterator_to_array($filter, false);

        $this->assertInstanceOf(All::class, $filter);
        $this->assertCount(2, $rules, 'The filter is not an All of the recovered_at condition and the tag sets');
        $this->assertSame([Unlike::class, 'recovered_at', '*'], $this->triple($rules[0]));
        $this->assertInstanceOf(Any::class, $rules[1], 'The tag sets are not combined with OR');

        $tagSetFilters = iterator_to_array($rules[1], false);

        $this->assertCount($tagSets, $tagSetFilters, 'Not every tag set got its own filter');

        $conditions = [];
        foreach ($tagSetFilters as $tagSetFilter) {
            $this->assertInstanceOf(All::class, $tagSetFilter, "A tag set's conditions are not combined with AND");

            foreach ($tagSetFilter as $rule) {
                $conditions[] = $this->triple($rule);
            }
        }

        return $conditions;
    }

    /**
     * Reduce the given rule to an [operator class, column, value] triple
     *
     * @return array{class-string, string|array<string>, mixed}
     */
    private function triple(Rule $rule): array
    {
        $this->assertInstanceOf(Condition::class, $rule);

        return [$rule::class, $rule->getColumn(), $rule->getValue()];
    }

    /**
     * Get the ids of the incidents the given instance yields, sorted for order-independent assertions
     *
     * @return list<int>
     */
    private function incidentIds(Incidents $incidents): array
    {
        $ids = [];
        foreach ($incidents as $incident) {
            $ids[] = $this->incidentId($incident);
        }

        sort($ids);

        return $ids;
    }

    /**
     * Get the id of the incident the given wrapper manages
     *
     * The wrapper doesn't expose it, but the tests need it to tell the seeded incidents apart.
     */
    private function incidentId(Incident $incident): int
    {
        /** @var IncidentModel $model */
        $model = (new ReflectionProperty($incident, 'incident'))->getValue($incident);

        return $model->id;
    }

    /**
     * Insert an incident for the object identified by the given source and tags, creating the
     * object and object_id_tag rows it relates to so the joins in {@see Incidents::buildQuery()} resolve.
     *
     * @param array<string, string> $tags
     * @param ?int $recoveredAt Recovery time in milliseconds; null leaves the incident open
     *
     * @return int The id of the inserted incident
     */
    private function seedIncident(int $sourceId, array $tags, ?int $recoveredAt = null): int
    {
        $objectId = $this->objectId($sourceId, $tags);

        $this->seedObject($sourceId, $tags, $objectId);

        $now = (int) (new DateTime())->format('Uv');

        $this->db->insert('incident', [
            'object_id'    => $objectId,
            'severity'     => 'crit',
            'started_at'   => ($recoveredAt ?? $now) - 1000,
            'recovered_at' => $recoveredAt
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a contact with the given username and return its id
     */
    private function seedContact(string $username): int
    {
        $this->db->insert('contact', [
            'external_uuid'      => sprintf('00000000-0000-0000-0000-%012x', crc32($username)),
            'full_name'          => $username,
            'username'           => $username,
            'default_channel_id' => self::$channelId,
            'changed_at'         => (int) (new DateTime())->format('Uv')
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Make the given contact a recipient, subscriber or manager of the given incident
     *
     * @param string $role One of `recipient`, `subscriber` or `manager`
     */
    private function seedRole(int $incidentId, int $contactId, string $role): void
    {
        $this->db->insert('incident_contact', [
            'incident_id' => $incidentId,
            'contact_id'  => $contactId,
            'role'        => $role,
            'changed_at'  => (int) (new DateTime())->format('Uv')
        ]);
    }

    /**
     * Derive a stable, unique object id for the given source and tags
     *
     * Stands in for the daemon's object hashing: the exact algorithm is irrelevant to these tests, it
     * only has to be deterministic and collision-free across distinct (source, tags) combinations so
     * the seeded incident and object_id_tag rows share one id while distinct objects differ.
     *
     * The id is returned in the representation the current database expects for a binary literal, as
     * the tests seed the tables directly, i.e. without the ORM's Binary behavior in between.
     *
     * @param array<string, string> $tags
     */
    private function objectId(int $sourceId, array $tags): string
    {
        ksort($tags);

        $hash = hash('sha256', $sourceId . "\0" . serialize($tags));

        if ($this->db->getAdapter() instanceof Pgsql) {
            return sprintf('\\x%s', $hash);
        }

        return hex2bin($hash);
    }

    /**
     * Insert the object row and the object_id_tag rows that represent the object for the given tags,
     * unless they already exist — the same object backs every incident sharing its id.
     *
     * The source the object belongs to is created as well, once per test and source id.
     *
     * @param array<string, string> $tags
     */
    private function seedObject(int $sourceId, array $tags, string $objectId): void
    {
        if (! isset($this->seededObjects[$sourceId])) {
            $this->seededObjects[$sourceId] = [];

            $this->db->insert('source', [
                'id'                => $sourceId,
                'type'              => 'test',
                'name'              => 'test',
                'listener_username' => sprintf('test_%d', $sourceId),
                'changed_at'        => (int) (new DateTime())->format('Uv')
            ]);
        }

        if (isset($this->seededObjects[$sourceId][$objectId])) {
            return;
        }

        $this->seededObjects[$sourceId][$objectId] = true;

        $this->db->insert('object', [
            'id'        => $objectId,
            'source_id' => $sourceId,
            'name'      => implode(', ', $tags)
        ]);

        foreach ($tags as $tag => $value) {
            $this->db->insert('object_id_tag', [
                'object_id' => $objectId,
                'tag'       => $tag,
                'value'     => $value
            ]);
        }
    }
}
