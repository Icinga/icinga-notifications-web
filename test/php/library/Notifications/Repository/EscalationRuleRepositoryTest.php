<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Form\Data\EscalationRecipient;
use Icinga\Module\Notifications\Form\Data\EscalationRule;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Repository\EscalationRuleRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\SchemaGroup;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see EscalationRuleRepository}.
 *
 * Unlike the mocked-connection repository tests, these run against real databases — once for MySQL and once for
 * PostgreSQL (see {@see DbTestBackends} / `#[DataProvider('sharedDatabases')]`). Each test performs an operation and
 * reads the result back from the database to verify what was persisted.
 *
 * The repository manages the escalation rule itself and orchestrates its escalations, delegating the escalation and
 * recipient details to {@see \Icinga\Module\Notifications\Repository\EscalationRepository} (covered by its own test).
 * These tests therefore focus on the rule and on the escalations being created, kept and removed as a whole.
 *
 * Each test runs inside its own transaction which is rolled back afterwards, so its writes don't leak into the next
 * test. A source, channel and contact are seeded per test in {@see self::initializeNotificationsDb()} and their ids
 * captured into {@see self::$sourceId}, {@see self::$channelId} and {@see self::$contactId} (the ids can't be assumed
 * as rolled-back transactions still advance the auto-increment).
 */
#[TransactionIsolation]
class EscalationRuleRepositoryTest extends TestCase
{
    use DbTestBackends;

    /** @var int Id of the source seeded per test, referenced by the rule */
    private static int $sourceId;

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
            'external_uuid' => '00000000-0000-0000-0000-0000000000c1', 'name' => 'Test', 'type' => 'email',
            'changed_at' => $now
        ]);
        self::$channelId = (int) $db->lastInsertId();
        $db->insert('source', [
            'type' => 'icinga2', 'name' => 'Test Source', 'listener_username' => 'test-source', 'changed_at' => $now
        ]);
        self::$sourceId = (int) $db->lastInsertId();
        $db->insert('contact', [
            'full_name' => 'Test', 'username' => 'test', 'default_channel_id' => self::$channelId,
            'external_uuid' => '00000000-0000-0000-0000-0000000000a1', 'changed_at' => $now
        ]);
        self::$contactId = (int) $db->lastInsertId();
    }

    /**
     * Build an escalation with a single contact recipient
     *
     * @param ?int $id
     * @param int $position
     * @param ?string $condition
     *
     * @return Escalation
     */
    private function escalation(?int $id, int $position, ?string $condition): Escalation
    {
        return new Escalation(
            $id,
            $position,
            $condition,
            [new EscalationRecipient(null, 'contact', self::$contactId, self::$channelId)]
        );
    }

    /**
     * Fetch the (non-deleted) escalations of the given rule, ordered by position
     *
     * @param Connection $db
     * @param int $ruleId
     *
     * @return RuleEscalation[]
     */
    private function escalationsOf(Connection $db, int $ruleId): array
    {
        return iterator_to_array(
            RuleEscalation::on($db)
                ->filter(Filter::equal('rule_id', $ruleId))
                ->filter(Filter::equal('deleted', 'n'))
                ->orderBy('position')
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->assertNull((new EscalationRuleRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheRuleAndItsEscalations(Connection $db): void
    {
        $repository = new EscalationRuleRepository($db);

        $id = $repository->create(new EscalationRule(
            null,
            'Create Rule',
            self::$sourceId,
            'host.name=foo',
            [
                $this->escalation(null, 0, null),
                $this->escalation(null, 1, 'incident_severity>=crit')
            ]
        ));

        $rule = $repository->find($id);
        $this->assertNotNull($rule, 'The created rule was not found');
        $this->assertSame('Create Rule', $rule->name);
        $this->assertEquals(self::$sourceId, $rule->source_id);
        $this->assertSame('host.name=foo', $rule->object_filter);
        $this->assertFalse($rule->deleted);

        $escalations = $this->escalationsOf($db, $id);
        $this->assertCount(2, $escalations, 'Both escalations should have been created');
        $this->assertSame(0, (int) $escalations[0]->position);
        $this->assertNull($escalations[0]->condition);
        $this->assertSame(1, (int) $escalations[1]->position);
        $this->assertSame('incident_severity>=crit', $escalations[1]->condition);

        // Each escalation has its recipient
        foreach ($escalations as $escalation) {
            $recipients = iterator_to_array($escalation->rule_escalation_recipient);
            $this->assertCount(1, $recipients, 'The escalation should have one recipient');
            $this->assertEquals(self::$contactId, $recipients[0]->contact_id);
        }
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesTheRuleAndSyncsItsEscalations(Connection $db): void
    {
        $repository = new EscalationRuleRepository($db);

        $id = $repository->create(new EscalationRule(
            null,
            'Update Rule',
            self::$sourceId,
            null,
            [
                $this->escalation(null, 0, null),
                $this->escalation(null, 1, 'incident_age>=5m')
            ]
        ));

        $created = $this->escalationsOf($db, $id);
        $this->assertCount(2, $created);
        $keptId = (int) $created[0]->id;

        // Rename the rule, set an object filter, keep the first escalation, drop the second and add a new one
        $repository->update(new EscalationRule(
            $id,
            'Renamed Rule',
            self::$sourceId,
            'service.name=bar',
            [
                $this->escalation($keptId, 0, 'incident_severity>=warning'),
                $this->escalation(null, 1, null)
            ]
        ));

        $rule = $repository->find($id);
        $this->assertSame('Renamed Rule', $rule->name);
        $this->assertSame('service.name=bar', $rule->object_filter);

        $escalations = $this->escalationsOf($db, $id);
        $this->assertCount(2, $escalations, 'The rule should still have two escalations');

        $byId = [];
        foreach ($escalations as $escalation) {
            $byId[(int) $escalation->id] = $escalation;
        }

        // The kept escalation was updated in place
        $this->assertArrayHasKey($keptId, $byId, 'The kept escalation must survive the update');
        $this->assertSame('incident_severity>=warning', $byId[$keptId]->condition);

        // The dropped escalation is gone, a brand-new one took the free position
        $this->assertArrayNotHasKey((int) $created[1]->id, $byId, 'The dropped escalation must be removed');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EscalationRuleRepository($db))->update(new EscalationRule(999, 'Nope', self::$sourceId, null, []));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheRuleAndItsEscalations(Connection $db): void
    {
        $repository = new EscalationRuleRepository($db);

        $id = $repository->create(new EscalationRule(
            null,
            'Delete Rule',
            self::$sourceId,
            null,
            [$this->escalation(null, 0, null)]
        ));

        $repository->delete($id);

        // find() filters out deleted rows, so the repository no longer returns the rule
        $this->assertNull($repository->find($id), 'A deleted rule must not be found anymore');

        // It's only soft-deleted though: the row still exists, flagged deleted
        $rule = Rule::on($db)->filter(Filter::equal('id', $id))->first();
        $this->assertNotNull($rule, 'The rule row should still exist');
        $this->assertTrue($rule->deleted, 'The rule should be soft-deleted, not removed');

        $this->assertCount(0, $this->escalationsOf($db, $id), 'The rule\'s escalations should be soft-deleted too');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsIfTheRuleDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EscalationRuleRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateRemovingAnEarlierEscalationRenumbersTheSurvivor(Connection $db): void
    {
        $repository = new EscalationRuleRepository($db);

        // A rule with escalations at positions 0 and 1
        $id = $repository->create(new EscalationRule(
            null,
            'Rule',
            self::$sourceId,
            null,
            [
                $this->escalation(null, 0, 'first'),
                $this->escalation(null, 1, 'second')
            ]
        ));

        $created = $this->escalationsOf($db, $id);
        $this->assertCount(2, $created);
        $survivorId = (int) $created[1]->id; // the escalation at position 1

        // Remove the escalation at position 0; the survivor (was position 1) keeps its id but is renumbered to 0 —
        // exactly what the form does. This must not collide on `uk_rule_escalation_rule_id_position` (the survivor is
        // moved into the slot the to-be-removed escalation still holds until it is deleted).
        $repository->update(new EscalationRule(
            $id,
            'Rule',
            self::$sourceId,
            null,
            [$this->escalation($survivorId, 0, 'second')]
        ));

        $escalations = $this->escalationsOf($db, $id);
        $this->assertCount(1, $escalations, 'Only the survivor should remain');
        $this->assertSame($survivorId, (int) $escalations[0]->id, 'The survivor must be kept (same id)');
        $this->assertSame(0, (int) $escalations[0]->position, 'The survivor must be renumbered to position 0');
        $this->assertSame('second', $escalations[0]->condition);
    }
}
