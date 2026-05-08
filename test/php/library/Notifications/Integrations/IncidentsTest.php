<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Integrations\Incidents;
use InvalidArgumentException;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class IncidentsTest extends TestCase
{
    private ?Connection $previousDatabaseInstance = null;

    /**
     * Snapshots the {@see Database} singleton so wiring tests can inject a stub {@see Connection}
     * without leaking state into the next test.
     */
    protected function setUp(): void
    {
        $instance = (new ReflectionClass(Database::class))->getProperty('instance');
        $this->previousDatabaseInstance = $instance->getValue();
    }

    /**
     * Restores the {@see Database} singleton to whatever it was before this test ran.
     */
    protected function tearDown(): void
    {
        $instance = (new ReflectionClass(Database::class))->getProperty('instance');
        $instance->setValue(null, $this->previousDatabaseInstance);
        $this->previousDatabaseInstance = null;
    }

    /**
     * Pinned vectors taken from an actual daemon's writes to a notifications database; each
     * `(source_id, tags) => hex` row was confirmed against the stored object.id. If a test here
     * fails, either the PHP hash function drifted or the daemon's hash function changed —
     * investigate which before "fixing" either side, because the stored object.id values in
     * production databases depend on this.
     *
     * @return array<string, array{0: int, 1: array<string, string>, 2: string}>
     */
    public static function knownHashVectors(): array
    {
        return [
            'single tag (host only)'    => [
                1,
                ['host' => 'icinga2'],
                'f2c11c8086bacbc6674b5e9e68fa5e7c9f8200be1e2e50825ee08a86bb301d13'
            ],
            'multiple tags (http svc)'  => [
                1,
                ['host' => 'icinga2', 'service' => 'http'],
                '58a0b5785f2ae8974f1c698ff0eb579b8b63c4110f991ce6286caa60d041c056'
            ],
            'multiple tags (procs svc)' => [
                1,
                ['host' => 'icinga2', 'service' => 'procs'],
                '7ecdee4e3dd82abc27aab1661b776b611c1a65a02594c423a1868daba8c99e8b'
            ],
        ];
    }

    /**
     * @param array<string, string> $tags
     */
    #[DataProvider('knownHashVectors')]
    public function testObjectIdMatchesDaemonHash(int $sourceId, array $tags, string $expectedHex): void
    {
        $this->assertSame($expectedHex, Incidents::objectId($sourceId, $tags));
    }

    public function testObjectIdIsIndependentOfTagInsertionOrder(): void
    {
        $a = Incidents::objectId(1, ['host' => 'icinga2', 'service' => 'http']);
        $b = Incidents::objectId(1, ['service' => 'http', 'host' => 'icinga2']);

        $this->assertSame($a, $b);
    }

    public function testObjectIdThrowsForNegativeSourceId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Incidents::objectId(-1, ['host' => 'icinga2']);
    }

    public function testBuildQueryFiltersOnObjectIdWithComputedHash(): void
    {
        $this->injectDatabase($this->createStub(Connection::class));

        $sourceId = 1;
        $tags = ['host' => 'icinga2', 'service' => 'http'];
        $expectedHash = Incidents::objectId($sourceId, $tags);

        $query = $this->inspectableIncidents($sourceId, $tags)->exposedBuildQuery();
        $equals = self::flattenEqualRules($query->getFilter());

        $matched = false;
        foreach ($equals as $rule) {
            if ($rule->getColumn() === 'object_id' && $rule->getValue() === $expectedHash) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue(
            $matched,
            'Expected the built query to carry a Filter\Equal on object_id with the computed hash'
        );
    }

    /**
     * Recursively collect every {@see Filter\Equal} rule below the given rule.
     *
     * @return Filter\Equal[]
     */
    private static function flattenEqualRules(Filter\Rule $rule): array
    {
        if ($rule instanceof Filter\Equal) {
            return [$rule];
        }

        $collected = [];
        if ($rule instanceof Filter\Chain) {
            foreach ($rule as $child) {
                $collected = array_merge($collected, self::flattenEqualRules($child));
            }
        }

        return $collected;
    }

    /**
     * Build an Incidents instance that exposes its protected {@see Incidents::buildQuery()} via a
     * public passthrough, so the test can inspect the resulting query without running it.
     *
     * @param array<string, string> $tags
     */
    private function inspectableIncidents(int $sourceId, array $tags): Incidents
    {
        return new class ($sourceId, $tags) extends Incidents {
            public function exposedBuildQuery(): Query
            {
                return $this->buildQuery();
            }
        };
    }

    /**
     * Replace the {@see Database} singleton with the given connection so the unit under test hits
     * the stub. Restored by tearDown via the snapshot taken in setUp.
     */
    private function injectDatabase(Connection $db): void
    {
        $instance = (new ReflectionClass(Database::class))->getProperty('instance');
        $instance->setValue(null, $db);
    }
}
