<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Behavior;

use DateTime;
use Icinga\Module\Notifications\Model\Behavior\SourceAggregator;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Test\DbTestBackends;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[TransactionIsolation]
class SourceAggregatorTest extends TestCase
{
    use DbTestBackends;

    /** @var Connection The database of the current test, set by every test using the shared databases */
    private Connection $db;

    /** @var int Number of sources seeded so far, keeps `source.listener_username` unique across all tests */
    private static int $sourceCount = 0;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        // Neither objects nor sources have prerequisites of their own
    }

    #[DataProvider('sharedDatabases')]
    public function testAggregatesEverySourceAnObjectIsLinkedTo(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Icinga 2'));
        $this->linkSource($objectId, $this->seedSource('gitlab', 'GitLab'));
        $this->seedIncident($objectId);

        $object = $this->loadObject('host-a');

        $this->assertIsArray(
            $object->sources,
            'The sources should be aggregated up front, not left as a lazily loaded relation query'
        );
        $this->assertSame(
            [['gitlab', 'GitLab'], ['icingadb', 'Icinga 2']],
            $this->sortedSourceTuples($object),
            'An object should be given every source it is linked to'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testKeepsSourcesSharingATypeApart(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Datacenter A'));
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Datacenter B'));
        $this->seedIncident($objectId);

        $this->assertSame(
            [['icingadb', 'Datacenter A'], ['icingadb', 'Datacenter B']],
            $this->sortedSourceTuples($this->loadObject('host-a')),
            'Sources sharing a type should be kept apart, deduplicating them is up to the consumer'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testOmitsSoftDeletedSources(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Icinga 2'));
        $this->linkSource($objectId, $this->seedSource('gitlab', 'GitLab', true));
        $this->seedIncident($objectId);

        $this->assertSame(
            [['icingadb', 'Icinga 2']],
            $this->sortedSourceTuples($this->loadObject('host-a')),
            'A soft-deleted source should be omitted, even if a link still exists'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testYieldsNoSourcesForAnObjectWithoutAny(Connection $db): void
    {
        $this->db = $db;

        $this->seedIncident($this->seedObject('host-a'));

        $this->assertSame(
            [],
            $this->loadObject('host-a')->sources,
            'An object without sources should be given an empty array, not the aggregate\'s NULL'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testYieldsEachObjectOnlyItsOwnSources(Connection $db): void
    {
        $this->db = $db;

        $hostA = $this->seedObject('host-a');
        $this->linkSource($hostA, $this->seedSource('icingadb', 'Icinga 2'));
        $this->seedIncident($hostA);

        $hostB = $this->seedObject('host-b');
        $this->linkSource($hostB, $this->seedSource('gitlab', 'GitLab'));
        $this->seedIncident($hostB);

        $this->assertSame(
            [['icingadb', 'Icinga 2']],
            $this->sortedSourceTuples($this->loadObject('host-a')),
            'An object should only be given the sources it is linked to itself'
        );
        $this->assertSame(
            [['gitlab', 'GitLab']],
            $this->sortedSourceTuples($this->loadObject('host-b')),
            'An object should only be given the sources it is linked to itself'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testDoesNotMultiplyTheRowsOfTheOuterQuery(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Icinga 2'));
        $this->linkSource($objectId, $this->seedSource('gitlab', 'GitLab'));
        $this->seedIncident($objectId);

        $incidents = Incident::on($db)->with('object')->withColumns(['object.sources']);

        $this->assertCount(
            1,
            iterator_to_array($incidents, false),
            'An incident should stay a single row, its object\'s sources should be aggregated, not joined'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAggregatesOnlyTheColumnsASourceIsRenderedFrom(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $sourceId = $this->seedSource('icingadb', 'Icinga 2');
        $this->linkSource($objectId, $sourceId);
        $this->seedIncident($objectId);

        $sources = $this->loadObject('host-a')->sources;
        $this->assertCount(1, $sources, 'The object\'s only source should be aggregated');

        $source = $sources[0];
        $this->assertInstanceOf(Source::class, $source, 'An aggregated source should be hydrated into a model');
        $this->assertEquals($sourceId, $source->id, 'A source\'s id should be aggregated');
        $this->assertSame('icingadb', $source->type, 'A source\'s type should be aggregated');
        $this->assertSame('Icinga 2', $source->name, 'A source\'s name should be aggregated');

        $this->assertFalse(
            $source->hasProperty('listener_username'),
            'A source model should be partial, it should not carry columns no consumer requires'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAggregatesSourcesWithTheObjectAsBaseModel(Connection $db): void
    {
        $this->db = $db;

        $objectId = $this->seedObject('host-a');
        $this->linkSource($objectId, $this->seedSource('icingadb', 'Icinga 2'));

        $object = Objects::on($db)
            ->withColumns(['sources'])
            ->filter(Filter::equal('name', 'host-a'))
            ->first();

        $this->assertNotNull($object, 'The seeded object should be found');
        $this->assertSame(
            [['icingadb', 'Icinga 2']],
            $this->sortedSourceTuples($object),
            'Sources should be aggregated with the object as the query\'s base model too, i.e. without a relation'
        );
    }

    public function testCannotBeUsedToPersistSources(): void
    {
        // `sources` is read-only, it doesn't exist as a column and hence can't be written to
        $this->expectException(InvalidColumnException::class);

        (new SourceAggregator())->toDb([], 'sources', new Objects());
    }

    /**
     * Load the object of the given name through an incident query with its sources aggregated
     */
    private function loadObject(string $name): Objects
    {
        $incident = Incident::on($this->db)
            ->with('object')
            ->withColumns(['object.sources'])
            ->filter(Filter::equal('object.name', $name))
            ->first();

        $this->assertNotNull($incident, "The incident seeded for object '$name' should be found");

        return $incident->object;
    }

    /**
     * Reduce the sources of the given object to a sorted list of type and name pairs
     *
     * The subquery the behavior builds is unordered, so assertions must not depend on
     * the order in which the sources are aggregated.
     *
     * @return list<array{string, string}>
     */
    private function sortedSourceTuples(Objects $object): array
    {
        $sources = [];
        foreach ($object->sources as $source) {
            $sources[] = [$source->type, $source->name];
        }

        sort($sources);

        return $sources;
    }

    /**
     * Insert an object row and return its id in the representation the current database expects
     */
    private function seedObject(string $name): string
    {
        // The tables are seeded directly, i.e. without the ORM's Binary behavior in between,
        // which is why PostgreSQL requires the hex literal notation
        $hash = hash('sha256', $name);
        $id = $this->db->getAdapter() instanceof Pgsql ? sprintf('\\x%s', $hash) : hex2bin($hash);

        $this->db->insert('object', ['id' => $id, 'name' => $name]);

        return $id;
    }

    /**
     * Insert a source row and return its id
     */
    private function seedSource(string $type, string $name, bool $deleted = false): int
    {
        // A soft-deleted source gets no listener_username, both because the repository frees it upon
        // deletion and because the source table's check constraint only permits its absence for those
        $this->db->insert('source', [
            'type'              => $type,
            'name'              => $name,
            'listener_username' => $deleted ? null : 'listener-' . ++self::$sourceCount,
            'changed_at'        => (int) (new DateTime())->format('Uv'),
            'deleted'           => $deleted ? 'y' : 'n'
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Link the given object with the given source
     */
    private function linkSource(string $objectId, int $sourceId): void
    {
        $this->db->insert('object_source', ['object_id' => $objectId, 'source_id' => $sourceId]);
    }

    /**
     * Insert an open incident for the given object and return its id
     */
    private function seedIncident(string $objectId): int
    {
        $this->db->insert('incident', [
            'object_id'  => $objectId,
            'severity'   => 'crit',
            'started_at' => (int) (new DateTime())->format('Uv')
        ]);

        return (int) $this->db->lastInsertId();
    }
}
