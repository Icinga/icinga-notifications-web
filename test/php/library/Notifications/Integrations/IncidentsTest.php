<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use DateTime;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Integrations\Incidents;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
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
 * rolled back afterwards, so the incidents and objects it seeds don't leak into the next test.
 *
 * The tests that only assert on the filter {@see Incidents} constructs don't need a database at all and use a
 * {@see TestConnection} instead.
 */
#[TransactionIsolation]
class IncidentsTest extends TestCase
{
    use DbTestBackends;

    /**
     * Object ids seeded into the current test's database, keyed by object id
     *
     * @var array<string, true>
     */
    private array $seededObjects = [];

    /** @var Connection The database of the current test, set by every test using the shared databases */
    private Connection $db;

    /**
     * Nothing has to exist before a test's transaction starts, every test seeds what it requires itself
     */
    protected static function initializeNotificationsDb(Connection $db): void
    {
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

    public function testObjectIdFor(): void
    {
        $tags1 = ['foo' => 'bar', 'baz' => 'qux'];
        $tags2 = ['baz' => 'qux', 'foo' => 'bar'];

        $id1 = self::invokeStatic('objectIdFor', $tags1);
        $id2 = self::invokeStatic('objectIdFor', $tags2);

        $this->assertSame($id1, $id2, 'The order of tags must not have an effect on the object id');
        // object id from the daemon's object_test.go
        $this->assertSame('4bfe8b2596005172c9db4d2b4b400a12b478b87a793ed9577e9d2d165fd07e7a', $id1);
    }

    public function testRejectsAnEmptyTagSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A set of id tags must not be empty');
        $this->conditions($this->builtFilter([]));
    }

    public function testRejectsAnEmptySet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one set of id tags is required');

        $this->query(new TestConnection(), []);
    }

    #[DataProvider('sharedDatabases')]
    public function testGetIterator(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(['host' => 'a']);
        $this->seedIncident(['host' => 'b']);

        $incidents = iterator_to_array($this->incidents($db, [['host' => 'a'], ['host' => 'b']]), false);

        $this->assertCount(2, $incidents);
        foreach ($incidents as $incident) {
            $this->assertInstanceOf(Incident::class, $incident);
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testCount(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(['host' => 'a']);

        $this->assertEquals(1, $this->incidents($db, [['host' => 'a']])->count());
    }

    #[DataProvider('sharedDatabases')]
    public function testExcludesClosedIncidents(Connection $db): void
    {
        $this->db = $db;

        // A recovered (closed) incident must never be yielded — the integration only deals with open
        // incidents (recovered_at IS NULL).
        $this->seedIncident(['host' => 'a']);
        $this->seedIncident(['host' => 'b'], 1_700_000_000_000);

        $incidents = $this->incidents($db, [['host' => 'a'], ['host' => 'b']]);

        $this->assertEquals(1, $incidents->count());
        $this->assertCount(1, iterator_to_array($incidents, false));
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentsOfObjectsCarryingAllGivenTags(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(['host' => 'a']);
        $service = $this->seedIncident(['host' => 'a', 'service' => 'http']);
        $this->seedIncident(['host' => 'b']);

        // The host's incident and the one of its service, matched by the tag they have in common
        $this->assertSame(
            [$host, $service],
            $this->incidentIds($this->incidents($db, [['host' => 'a']])),
            'Not all incidents of the objects carrying the given tag were matched'
        );

        // Only the host's incident, as a null value requires the tag's absence
        $this->assertSame(
            [$host],
            $this->incidentIds($this->incidents($db, [['host' => 'a', 'service' => null]])),
            'A tag given as null did not exclude the objects carrying it'
        );

        // Only the service's incident, as its object is the only one carrying both tags
        $this->assertSame(
            [$service],
            $this->incidentIds($this->incidents($db, [['host' => 'a', 'service' => 'http']])),
            'Not only the incidents of the object carrying all given tags were matched'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentOfTheObjectCarryingExactlyTheGivenTags(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(['host' => 'a']);
        $service = $this->seedIncident(['host' => 'a', 'service' => 'http']);

        $this->assertSame(
            [$host],
            $this->incidentIds($this->incidents($db, [['host' => 'a']], true)),
            "The incident of the object carrying exactly the host's tags was not matched"
        );

        $this->assertSame(
            [$service],
            $this->incidentIds($this->incidents($db, [['host' => 'a', 'service' => 'http']], true)),
            "The incident of the object carrying exactly the service's tags was not matched"
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesNoIncidentOfObjectsCarryingTagsBesidesTheGivenOnes(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident(['host' => 'a', 'service' => 'http']);

        $this->assertSame(
            [],
            $this->incidentIds($this->incidents($db, [['host' => 'a']], true)),
            'The incident of an object carrying tags besides the given ones was matched'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testMatchesTheIncidentsOfObjectsSatisfyingAnyGivenTagSet(Connection $db): void
    {
        $this->db = $db;

        $host = $this->seedIncident(['host' => 'a']);
        $service = $this->seedIncident(['host' => 'b', 'service' => 'http']);
        $this->seedIncident(['host' => 'c']);

        // An object only has to satisfy any of the tag sets, each of which identifies it completely
        $this->assertSame(
            [$host, $service],
            $this->incidentIds($this->incidents($db, [['host' => 'a'], ['host' => 'b', 'service' => 'http']], true)),
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
        return $this->query(new TestConnection(), [$tags])->getFilter();
    }

    /**
     * Create an instance matching the incidents of the given tag sets, reading through the given database
     *
     * @param iterable<array<string, ?string>> $tagSets
     * @param bool $exactMatches Whether an object must not have tags besides those given in a set,
     *                           as {@see Incidents::getAll()} requires it
     */
    private function incidents(Connection $db, iterable $tagSets, bool $exactMatches = false): Incidents
    {
        return new Incidents($this->query($db, $tagSets, $exactMatches));
    }

    /**
     * Assemble the query the factories of {@see Incidents} would pass to its constructor
     *
     * The constructor only accepts a query, which the factories build with the class' own helpers while
     * reading through the singleton. The tests use those helpers as well, as what they assemble is what
     * the assertions are about, but pass the database explicitly.
     *
     * @param iterable<array<string, ?string>> $tagSets
     * @param bool $exactMatches Whether an object must not have tags besides those given in a set
     *
     * @return Query<IncidentModel>
     */
    private function query(Connection $db, iterable $tagSets, bool $exactMatches = false): Query
    {
        return self::invokeStatic('openIncidents', $db)->filter(self::invokeStatic(
            $exactMatches ? 'tagSetFilterExactMatches' : 'tagSetFilterPartialMatches',
            $tagSets
        ));
    }

    /**
     * Call the given private static method of {@see Incidents} with the given arguments
     */
    private static function invokeStatic(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(Incidents::class, $method))->invoke(null, ...$args);
    }

    /**
     * Get the conditions of the tag set filters of the given query filter
     *
     * Asserts that the filter is an `All` of the recovered_at condition and an `Any` with one `All` per tag set,
     * then returns the conditions of those sets as [operator class, column, value] triples for order-independent
     * assertions. The recovered_at condition is not among them, it belongs to no tag set and is asserted here.
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
     * Insert an incident for the object identified by the given tags, creating the object and
     * object_id_tag rows it relates to so the incident's reference and the joins in the query of
     * {@see self::query()} resolve.
     *
     * @param array<string, string> $tags
     * @param ?int $recoveredAt Recovery time in milliseconds; null leaves the incident open
     *
     * @return int The id of the inserted incident
     */
    private function seedIncident(array $tags, ?int $recoveredAt = null): int
    {
        $objectId = $this->objectId($tags);

        $this->seedObject($tags, $objectId);

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
     * Get the id of the object identified by the given tags
     *
     * The id is derived exactly the way {@see Incidents} derives the ids it looks incidents up by, as
     * only then do the seeded rows match what the queries under test search for.
     *
     * The id is returned in the representation the current database expects for a binary literal, as
     * the tests seed the tables directly, i.e. without the ORM's Binary behavior in between.
     *
     * @param array<string, string> $tags
     */
    private function objectId(array $tags): string
    {
        $id = self::invokeStatic('objectIdFor', $tags);

        if ($this->db->getAdapter() instanceof Pgsql) {
            return sprintf('\\x%s', $id);
        }

        return hex2bin($id);
    }

    /**
     * Insert the object row and the object_id_tag rows that represent the object for the given tags,
     * unless they already exist — the same object backs every incident sharing its id.
     *
     * @param array<string, string> $tags
     */
    private function seedObject(array $tags, string $objectId): void
    {
        if (isset($this->seededObjects[$objectId])) {
            return;
        }

        $this->seededObjects[$objectId] = true;

        $this->db->insert('object', [
            'id'   => $objectId,
            'name' => implode(', ', $tags)
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
