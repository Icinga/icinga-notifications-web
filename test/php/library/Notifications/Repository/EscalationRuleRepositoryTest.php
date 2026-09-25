<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Form\Data\RuleEntry as RuleEntryData;
use Icinga\Module\Notifications\Form\Data\RuleEntryRecipient as RuleEntryRecipientData;
use Icinga\Module\Notifications\Form\Data\Rule as RuleData;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Repository\RuleEntryRepository;
use Icinga\Module\Notifications\Repository\RuleRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see RuleRepository}.
 *
 * Unlike the mocked-connection repository tests, these run against real databases — once for MySQL and once for
 * PostgreSQL (see {@see DbTestBackends} / `#[DataProvider('sharedDatabases')]`). Each test performs an operation and
 * reads the result back from the database to verify what was persisted.
 *
 * The repository manages the escalation rule itself and orchestrates its escalations, delegating the escalation and
 * recipient details to {@see \Icinga\Module\Notifications\Repository\RuleEntryRepository} (covered by its own test).
 * These tests therefore focus on the rule and on the escalations being created, kept and removed as a whole.
 *
 * Each test runs inside its own transaction which is rolled back afterwards, so its writes don't leak into the next
 * test. A source, channel and contact are seeded per test in {@see self::initializeNotificationsDb()} and their ids
 * captured into {@see self::$channelId} and {@see self::$contactId} (the ids can't be assumed
 * as rolled-back transactions still advance the auto-increment).
 */
#[TransactionIsolation]
class EscalationRuleRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int Id of the channel seeded per test */
    private static int $channelId;

    /** @var int Id of the contact seeded per test, used as the recipient */
    private static int $contactId;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        $now = (int) (new DateTime())->format('Uv');

        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('channel', [
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000c1'),
            'name' => 'Test', 'type' => 'email',
            'changed_at' => $now
        ]);
        self::$channelId = (int) $db->lastInsertId();
        $db->insert('source', [
            'type' => 'icinga2', 'name' => 'Test Source', 'listener_username' => 'test-source', 'changed_at' => $now
        ]);
        $db->insert('contact', [
            'full_name' => 'Test', 'username' => 'test', 'default_channel_id' => self::$channelId,
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000a1'),
            'changed_at' => $now
        ]);
        self::$contactId = (int) $db->lastInsertId();
    }

    /**
     * Build an escalation with a single contact recipient
     *
     * @param ?int $id
     * @param int $position
     * @param ?string $condition
     * @param ?int $ruleId
     *
     * @return RuleEntryData
     */
    private function escalation(?int $id, int $position, ?string $condition, ?int $ruleId): RuleEntryData
    {
        return new RuleEntryData(
            $id,
            $position,
            $condition,
            [new RuleEntryRecipientData(null, 'contact', self::$contactId, self::$channelId)],
            $ruleId
        );
    }

    /**
     * Fetch the (non-deleted) escalations of the given rule, ordered by position
     *
     * @param Connection $db
     * @param int $ruleId
     *
     * @return RuleEntry[]
     */
    private function escalationsOf(Connection $db, int $ruleId): array
    {
        return iterator_to_array(
            RuleEntry::on($db)
                ->filter(Filter::equal('rule_id', $ruleId))
                ->orderBy('position')
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->assertNull((new RuleRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheRuleAndItsEscalations(Connection $db): void
    {
        $repository = new RuleRepository($db);

        $id = $repository->create(new RuleData(
            null,
            'Create Rule',
            'escalation',
            'icinga2',
            'host.name=foo'
        ));

        $rule = $repository->find($id);
        $this->assertNotNull($rule, 'The created rule was not found');
        $this->assertSame('Create Rule', $rule->name);
        $this->assertEquals('icinga2', $rule->source_type);
        $this->assertSame('host.name=foo', $rule->object_filter);
        $this->assertFalse($rule->deleted);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesTheRule(Connection $db): void
    {
        $repository = new RuleRepository($db);

        $id = $repository->create(new RuleData(
            null,
            'Update Rule',
            'escalation',
            'icinga2',
            null
        ));

        // Rename the rule, set an object filter
        $repository->update(new RuleData(
            $id,
            'Renamed Rule',
            'escalation',
            'icinga2',
            'service.name=bar'
        ));

        $rule = $repository->find($id);
        $this->assertSame('Renamed Rule', $rule->name);
        $this->assertSame('service.name=bar', $rule->object_filter);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RuleRepository($db))->update(new RuleData(999, 'Nope', 'escalation', 'icinga2', null));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheRuleAndItsEscalations(Connection $db): void
    {
        $repository = new RuleRepository($db);

        $id = $repository->create(new RuleData(
            null,
            'Delete Rule',
            'escalation',
            'icinga2',
            null
        ));
        (new RuleEntryRepository($db))->create($this->escalation(null, 0, null, $id));

        $repository->delete($id);

        // find() filters out deleted rows, so the repository no longer returns the rule
        $this->assertNull($repository->find($id), 'A deleted rule must not be found anymore');

        // It's only soft-deleted though: the row still exists, flagged deleted
        $rule = $this->loadRawEntity($db, $id, Rule::class);
        $this->assertNotNull($rule, 'The rule row should still exist');
        $this->assertSame('y', $rule->deleted, 'The rule should be soft-deleted, not removed');

        $this->assertCount(0, $this->escalationsOf($db, $id), 'The rule\'s escalations should be soft-deleted too');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RuleRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testDuplicateThrowsWhenTheOriginalDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RuleRepository($db))->duplicate(new RuleData(999, 'Copy', 'escalation', 'test', null));
    }

    #[DataProvider('sharedDatabases')]
    public function testDuplicateAlsoCopiesEscalations(Connection $db): void
    {
        $repository = new RuleRepository($db);
        $originalId = $repository->create(new RuleData(null, 'Original', 'escalation', 'test', null));

        (new RuleEntryRepository($db))->create($this->escalation(null, 1, 'incident_age>1h', $originalId));

        // Create and directly remove an escalation to verify it is not revived by the duplication
        $toRemove = (new RuleEntryRepository($db))->create($this->escalation(null, 0, null, $originalId));
        (new RuleEntryRepository($db))->delete($toRemove);

        $copyId = $repository->duplicate(new RuleData($originalId, 'Copy', 'escalation', 'test', null));
        $this->assertNotSame($originalId, $copyId);

        $copyEscalations = $this->escalationsOf($db, $copyId);
        $this->assertCount(
            1,
            $copyEscalations,
            'Only one escalation should be copied, the deleted one must not be revived'
        );

        $this->assertSame(
            'incident_age>1h',
            $copyEscalations[0]->condition,
            'The copied escalation should have the same condition as the original'
        );

        // The copies are independent rows, not the originals
        $originalIds = array_map(fn ($r) => (int) $r->id, $this->escalationsOf($db, $originalId));
        foreach ($copyEscalations as $rotation) {
            $this->assertNotContains((int) $rotation->id, $originalIds, 'A duplicated escalation must be a new row');
        }

        // Recipients are copied and mapped by type
        $recipient = $copyEscalations[0]->rule_entry_recipient->first();
        $this->assertSame(self::$contactId, $recipient->contact_id);
    }
}
