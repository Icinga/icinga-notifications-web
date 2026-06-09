<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Integrations\Incidents;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;

class IncidentsTest extends TestCase
{
    /** @var array<string, true> Object ids seeded into the current test's database, keyed by hex id */
    private array $seededObjects = [];

    /**
     * Reset the set of seeded object ids so each test starts with a fresh, empty database
     */
    protected function setUp(): void
    {
        $this->seededObjects = [];
    }

    public function testBuildQueryMatchesAHostAndAllItsServices(): void
    {
        $db = $this->createDatabase();
        $this->seedHostTopology($db, 1);

        $incidents = $this->inspectableIncidents(['host' => 'icinga2'], $db)
            ->exposedBuildQuery()
            ->execute();

        $this->assertSame([1, 2, 3, 4], $this->idsOf($incidents));
    }

    public function testBuildQueryMatchesAnExactService(): void
    {
        $db = $this->createDatabase();
        $this->seedHostTopology($db, 1);

        $incidents = $this->inspectableIncidents(['host' => 'icinga2', 'service' => 'http'], $db)
            ->exposedBuildQuery()
            ->execute();

        $this->assertSame([1], $this->idsOf($incidents));
    }

    public function testBuildQueryMatchesAHostWithoutItsServices(): void
    {
        $db = $this->createDatabase();
        $this->seedHostTopology($db, 1);

        $incidents = $this->inspectableIncidents(['host' => 'icinga2', 'service' => null], $db)
            ->exposedBuildQuery()
            ->execute();

        $this->assertSame([4], $this->idsOf($incidents));
    }

    public function testGetIterator(): void
    {
        $db = $this->createDatabase();
        $sourceId = 1;
        $tags = ['host' => 'icinga2', 'service' => 'http'];

        $this->seedIncident($db, $sourceId, $tags);
        $this->seedIncident($db, $sourceId, $tags);
        $this->seedIncident($db, $sourceId, ['host' => 'elsewhere']);
        $this->seedIncident($db, 2, $tags);

        $incidents = iterator_to_array(new Incidents(['host' => 'icinga2'], $db), false);

        $this->assertCount(3, $incidents);
        foreach ($incidents as $incident) {
            $this->assertInstanceOf(Incident::class, $incident);
        }
    }

    public function testHasIncident(): void
    {
        $db = $this->createDatabase();
        $sourceId = 1;
        $tags = ['host' => 'icinga2'];
        $this->seedIncident($db, $sourceId, $tags);

        $this->assertTrue((new Incidents($tags, $db))->hasIncident());
        $this->assertFalse((new Incidents(['host' => 'elsewhere'], $db))->hasIncident());
    }

    /**
     * Build an Incidents instance reading through the given connection that exposes its protected
     * {@see Incidents::buildQuery()} via a public passthrough, so the test can inspect the resulting
     * query without running it.
     *
     * @param array<string, ?string> $tags
     */
    private function inspectableIncidents(array $tags, Connection $db): Incidents
    {
        return new class ($tags, $db) extends Incidents {
            public function exposedBuildQuery(): Query
            {
                return $this->buildQuery();
            }
        };
    }

    /**
     * Seed the host/service topology the buildQuery tests share: the host `icinga2` with the services
     * http, ssh and procs, the bare host itself, and an unrelated host `elsewhere`.
     *
     * The incidents are seeded in id order: 1 = http, 2 = ssh, 3 = procs, 4 = bare host, 5 = elsewhere.
     */
    private function seedHostTopology(RecordingConnection $db, int $sourceId): void
    {
        $this->seedIncident($db, $sourceId, ['host' => 'icinga2', 'service' => 'http']);
        $this->seedIncident($db, $sourceId, ['host' => 'icinga2', 'service' => 'ssh']);
        $this->seedIncident($db, $sourceId, ['host' => 'icinga2', 'service' => 'procs']);
        $this->seedIncident($db, $sourceId, ['host' => 'icinga2']);
        $this->seedIncident($db, $sourceId, ['host' => 'elsewhere', 'service' => 'http']);
    }

    /**
     * Collect the distinct ids of the given incidents, sorted ascending
     *
     * The query makes no promise about result order, so the ids are sorted to give the assertions a
     * stable, readable expectation to compare against.
     *
     * @param iterable<object{id: int}> $incidents
     *
     * @return list<int>
     */
    private function idsOf(iterable $incidents): array
    {
        $ids = [];
        foreach ($incidents as $incident) {
            $ids[$incident->id] = true;
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Stand up an in-memory SQLite database with the incident and object_id_tag tables that
     * {@see Incidents} reads from, and return it so tests can seed it and hand it to the unit under test.
     */
    private function createDatabase(): RecordingConnection
    {
        $db = new RecordingConnection(['db' => 'sqlite', 'dbname' => ':memory:']);
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
     * object_id_tag rows it relates to so the subqueries in {@see Incidents::buildQuery()} resolve.
     *
     * @param array<string, string> $tags
     */
    private function seedIncident(RecordingConnection $db, int $sourceId, array $tags): void
    {
        $objectId = $this->objectId($sourceId, $tags);

        $this->seedObjectTags($db, $tags, $objectId);

        $db->insert('incident', ['object_id' => hex2bin($objectId), 'severity' => 'crit']);
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
     * Insert the object_id_tag rows that represent the object for the given tags, unless they already
     * exist — the same object backs every incident sharing its id.
     *
     * The object id is stored as the raw bytes the Binary behavior compares against.
     *
     * @param array<string, string> $tags
     */
    private function seedObjectTags(RecordingConnection $db, array $tags, string $objectId): void
    {
        if (isset($this->seededObjects[$objectId])) {
            return;
        }

        $this->seededObjects[$objectId] = true;

        $rawId = hex2bin($objectId);

        foreach ($tags as $tag => $value) {
            $db->insert('object_id_tag', [
                'object_id' => $rawId,
                'tag'       => $tag,
                'value'     => $value,
            ]);
        }
    }
}
