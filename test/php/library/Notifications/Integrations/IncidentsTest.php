<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Integrations\Incidents;
use ipl\Orm\Query;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\Unlike;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;

class IncidentsTest extends TestCase
{
    /** @var array<string, true> Object ids seeded into the current test's database, keyed by hex id */
    private array $seededObjects = [];

    private RecordingConnection $db;

    /**
     * Reset the set of seeded object ids so each test starts with a fresh, empty database
     */
    protected function setUp(): void
    {
        $this->seededObjects = [];
        $this->db = $this->createDatabase();
    }

    public function testBuildsAnEqualFilterForEachTagWithAValue(): void
    {
        $conditions = $this->conditions($this->builtFilter(['host' => 'icinga2', 'service' => 'http']));

        $this->assertCount(3, $conditions);
        $this->assertContains([Unlike::class, 'recovered_at', '*'], $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.host', 'icinga2'], $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.service', 'http'], $conditions);
    }

    public function testBuildsAnAbsenceFilterForTagsGivenAsNull(): void
    {
        $conditions = $this->conditions($this->builtFilter(['host' => 'icinga2', 'service' => null]));

        $this->assertCount(3, $conditions);
        $this->assertContains([Equal::class, 'incident.object.tag.host', 'icinga2'], $conditions);
        // A null value requires the tag's absence, expressed as "no value matches the wildcard".
        $this->assertContains([Unlike::class, 'incident.object.tag.service', '*'], $conditions);
    }

    public function testAlwaysFiltersOutRecoveredIncidents(): void
    {
        // Even without any tags the query is constrained to open incidents (recovered_at IS NULL).
        $this->assertSame([[Unlike::class, 'recovered_at', '*']], $this->conditions($this->builtFilter([])));
    }

    public function testGetIterator(): void
    {
        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b']);

        $incidents = iterator_to_array(new Incidents([], $this->db), false);

        $this->assertCount(2, $incidents);
        foreach ($incidents as $incident) {
            $this->assertInstanceOf(Incident::class, $incident);
        }
    }

    public function testHasIncident(): void
    {
        $this->assertFalse((new Incidents([], $this->db))->hasIncident());

        $this->seedIncident(1, ['host' => 'a']);

        $this->assertTrue((new Incidents([], $this->db))->hasIncident());
    }

    public function testIteratingAfterHasIncidentYieldsAllMatchesFromTheSameInstance(): void
    {
        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b']);

        $incidents = new Incidents([], $this->db);

        $this->assertTrue($incidents->hasIncident());
        $this->assertCount(2, iterator_to_array($incidents, false));
    }

    public function testCount(): void
    {
        $this->seedIncident(1, ['host' => 'a']);

        $this->assertEquals(1, (new Incidents([], $this->db))->count());
    }

    public function testExcludesClosedIncidents(): void
    {
        // A recovered (closed) incident must never be yielded — the integration only deals with open
        // incidents (recovered_at IS NULL).
        $this->seedIncident(1, ['host' => 'a']);
        $this->seedIncident(2, ['host' => 'b'], 1_700_000_000_000);

        $incidents = new Incidents([], $this->db);

        $this->assertEquals(1, $incidents->count());
        $this->assertCount(1, iterator_to_array($incidents, false));
    }

    /**
     * Build the query {@see Incidents} would run for the given tags and return its filter
     *
     * The query is never executed: with tags it compiles to multi-argument COUNT(DISTINCT …) subqueries
     * that only MySQL and PostgreSQL support, not the sqlite the test database uses. Asserting on the
     * filter the integration constructs is this unit's responsibility; turning it into SQL is ipl-orm's.
     *
     * @param array<string, ?string> $tags
     */
    private function builtFilter(array $tags): Chain
    {
        $incidents = new Incidents($tags, $this->db);

        /** @var Query $query */
        $query = (new ReflectionMethod($incidents, 'buildQuery'))->invoke($incidents);

        return $query->getFilter();
    }

    /**
     * Reduce a filter chain to a list of [operator class, column, value] triples for order-independent assertions
     *
     * @return list<array{class-string, string|array<string>, mixed}>
     */
    private function conditions(Chain $chain): array
    {
        $conditions = [];
        foreach ($chain as $rule) {
            $this->assertInstanceOf(Condition::class, $rule);
            $conditions[] = [$rule::class, $rule->getColumn(), $rule->getValue()];
        }

        return $conditions;
    }

    /**
     * Stand up an in-memory SQLite database with the incident, object and object_id_tag tables that
     * {@see Incidents} reads from, and return it so tests can seed it and hand it to the unit under test.
     */
    private function createDatabase(): RecordingConnection
    {
        $db = new RecordingConnection(['db' => 'sqlite', 'dbname' => ':memory:']);
        $db->exec(
            'CREATE TABLE object (id BLOB PRIMARY KEY, source_id INTEGER, name VARCHAR, url VARCHAR);'
        );
        $db->exec(
            'CREATE TABLE object_id_tag (object_id BLOB, tag VARCHAR, value VARCHAR, PRIMARY KEY (object_id, tag));'
        );
        $db->exec(
            'CREATE TABLE incident (id INTEGER PRIMARY KEY AUTOINCREMENT, object_id BLOB, started_at INTEGER,'
            . ' recovered_at INTEGER, severity VARCHAR, mute_reason VARCHAR);'
        );

        return $db;
    }

    /**
     * Insert an incident for the object identified by the given source and tags, creating the
     * object and object_id_tag rows it relates to so the joins in {@see Incidents::buildQuery()} resolve.
     *
     * @param array<string, string> $tags
     * @param ?int $recoveredAt Recovery time in milliseconds; null leaves the incident open
     */
    private function seedIncident(int $sourceId, array $tags, ?int $recoveredAt = null): void
    {
        $objectId = $this->objectId($sourceId, $tags);

        $this->seedObject($sourceId, $tags, $objectId);

        $this->db->insert('incident', [
            'object_id'    => hex2bin($objectId),
            'severity'     => 'crit',
            'recovered_at' => $recoveredAt,
        ]);
    }

    /**
     * Derive a stable, unique object id for the given source and tags
     *
     * Stands in for the daemon's object hashing: the exact algorithm is irrelevant to these tests, it
     * only has to be deterministic and collision-free across distinct (source, tags) combinations so
     * the seeded incident and object_id_tag rows share one id while distinct objects differ.
     *
     * @param array<string, string> $tags
     */
    private function objectId(int $sourceId, array $tags): string
    {
        ksort($tags);

        return hash('sha256', $sourceId . "\0" . serialize($tags));
    }

    /**
     * Insert the object row and the object_id_tag rows that represent the object for the given tags,
     * unless they already exist — the same object backs every incident sharing its id.
     *
     * The object id is stored as the raw bytes the Binary behavior compares against.
     *
     * @param array<string, string> $tags
     */
    private function seedObject(int $sourceId, array $tags, string $objectId): void
    {
        if (isset($this->seededObjects[$objectId])) {
            return;
        }

        $this->seededObjects[$objectId] = true;

        $rawId = hex2bin($objectId);

        $this->db->insert('object', [
            'id'        => $rawId,
            'source_id' => $sourceId,
            'name'      => implode(', ', $tags),
        ]);

        foreach ($tags as $tag => $value) {
            $this->db->insert('object_id_tag', [
                'object_id' => $rawId,
                'tag'       => $tag,
                'value'     => $value,
            ]);
        }
    }
}
