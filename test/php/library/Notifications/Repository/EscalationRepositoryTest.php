<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Form\Data\Escalation;
use Icinga\Module\Notifications\Form\Data\EscalationRecipient;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Repository\EscalationRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see EscalationRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. A source, channel and contact are seeded per test in
 * {@see self::initializeNotificationsDb()} to satisfy the rule's and the recipients' foreign keys, and their ids
 * captured into {@see self::$channelId} and {@see self::$contactId} (the ids can't be assumed
 * as rolled-back transactions still advance the auto-increment).
 *
 * Escalations are unique by `(rule_id, position)`, so each test creates its own throwaway rule and puts its
 * escalation there.
 */
#[TransactionIsolation]
class EscalationRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    /** @var int Id of the contact seeded per test, used as the recipient */
    private static int $contactId;

    /** @var int Id of the channel seeded per test */
    private static int $channelId;

    /** @var int Id of the contact group seeded per test, used as a recipient */
    private static int $contactgroupId;

    /** @var int Id of the schedule seeded per test, used as a recipient */
    private static int $scheduleId;

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
        $db->insert('contactgroup', [
            'name' => 'Test Group',
            'external_uuid' => static::transformUUIDForDB($db, '00000000-0000-0000-0000-0000000000b1'),
            'changed_at' => $now
        ]);
        self::$contactgroupId = (int) $db->lastInsertId();
        $db->insert('schedule', ['name' => 'Test Schedule', 'timezone' => 'Europe/Berlin', 'changed_at' => $now]);
        self::$scheduleId = (int) $db->lastInsertId();
    }

    /**
     * Create a throwaway rule and return its id
     *
     * @param Connection $db
     *
     * @return int
     */
    private function createRule(Connection $db): int
    {
        $db->insert('rule', [
            'name' => 'Rule', 'source_type' => 'icinga2', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Build an escalation for the given rule with a single contact recipient
     *
     * @param int $ruleId
     * @param int $position
     * @param ?string $condition
     *
     * @return Escalation
     */
    private function escalation(int $ruleId, int $position, ?string $condition): Escalation
    {
        return new Escalation(
            null,
            $position,
            $condition,
            [new EscalationRecipient(null, 'contact', self::$contactId, self::$channelId)],
            $ruleId
        );
    }

    /**
     * Get the escalation's non-deleted recipients
     *
     * @param Connection $db
     * @param int $escalationId
     *
     * @return RuleEscalationRecipient[]
     */
    private function recipientsOf(Connection $db, int $escalationId): array
    {
        return iterator_to_array(
            RuleEscalationRecipient::on($db)
                ->filter(Filter::equal('rule_escalation_id', $escalationId))
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheEscalationDoesNotExist(Connection $db): void
    {
        $this->assertNull((new EscalationRepository($db))->find(999));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateThrowsWithoutARuleId(Connection $db): void
    {
        $this->expectException(LogicException::class);

        (new EscalationRepository($db))->create(
            new Escalation(null, 0, null, [new EscalationRecipient(null, 'contact', self::$contactId, null)])
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheEscalationWithRecipients(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);

        $id = $repository->create($this->escalation($ruleId, 0, 'incident_severity>=crit'));

        $escalation = $repository->find($id);
        $this->assertNotNull($escalation, 'The created escalation was not found');
        $this->assertEquals($ruleId, $escalation->rule_id);
        $this->assertSame(0, (int) $escalation->position);
        $this->assertSame('incident_severity>=crit', $escalation->condition);

        $recipients = $this->recipientsOf($db, $id);
        $this->assertCount(1, $recipients);
        $this->assertEquals(self::$contactId, $recipients[0]->contact_id);
        $this->assertEquals(self::$channelId, $recipients[0]->channel_id);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesTheEscalationAndSyncsRecipients(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);
        $id = $repository->create($this->escalation($ruleId, 0, null));

        // Change the condition and replace the recipient set (drop the old contact recipient, add a fresh one)
        $repository->update(new Escalation(
            $id,
            0,
            'incident_age>=5m',
            [new EscalationRecipient(null, 'contact', self::$contactId, null)],
            $ruleId
        ));

        $escalation = $repository->find($id);
        $this->assertSame('incident_age>=5m', $escalation->condition);

        $recipients = $this->recipientsOf($db, $id);
        $this->assertCount(1, $recipients, 'The recipient set should have been synced');
        $this->assertEquals(self::$contactId, $recipients[0]->contact_id);
        $this->assertNull($recipients[0]->channel_id, 'The new recipient uses the default channel');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheEscalationDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EscalationRepository($db))->update(new Escalation(999, 0, null, [], 1));
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheEscalationAndItsRecipients(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);
        $id = $repository->create($this->escalation($ruleId, 0, null));

        $repository->delete($id);

        // The repository hides deleted escalations
        $this->assertNull($repository->find($id), 'A deleted escalation must not be found anymore');

        // But it's only soft-deleted: the row still exists, flagged deleted with its position freed
        $escalation = $this->loadRawEntity($db, $id, RuleEscalation::class);
        $this->assertNotNull($escalation, 'The escalation row should still exist');
        $this->assertSame('y', $escalation->deleted, 'The escalation should be soft-deleted, not removed');
        $this->assertNull($escalation->position, 'The freed position should be nulled');

        $this->assertCount(0, $this->recipientsOf($db, $id), 'The recipients should be soft-deleted too');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheEscalationDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EscalationRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresAContactGroupRecipient(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);

        $id = $repository->create(new Escalation(
            null,
            0,
            null,
            [new EscalationRecipient(null, 'contact_group', self::$contactgroupId, self::$channelId)],
            $ruleId
        ));

        $recipients = $this->recipientsOf($db, $id);
        $this->assertCount(1, $recipients);
        $this->assertEquals(self::$contactgroupId, $recipients[0]->contactgroup_id);
        $this->assertNull($recipients[0]->contact_id, 'Only the contactgroup_id key should be set');
        $this->assertNull($recipients[0]->schedule_id, 'Only the contactgroup_id key should be set');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresAScheduleRecipient(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);

        $id = $repository->create(new Escalation(
            null,
            0,
            null,
            [new EscalationRecipient(null, 'schedule', self::$scheduleId, self::$channelId)],
            $ruleId
        ));

        $recipients = $this->recipientsOf($db, $id);
        $this->assertCount(1, $recipients);
        $this->assertEquals(self::$scheduleId, $recipients[0]->schedule_id);
        $this->assertNull($recipients[0]->contact_id, 'Only the schedule_id key should be set');
        $this->assertNull($recipients[0]->contactgroup_id, 'Only the schedule_id key should be set');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateUpdatesAKeptRecipientInPlaceAndNullsOppositeKeys(Connection $db): void
    {
        $ruleId = $this->createRule($db);
        $repository = new EscalationRepository($db);

        // Start with a contact recipient (using its default channel)
        $id = $repository->create($this->escalation($ruleId, 0, null));
        $recipientId = (int) $this->recipientsOf($db, $id)[0]->id;

        // Keep the very same recipient row (by id) but turn it into a schedule recipient
        $repository->update(new Escalation(
            $id,
            0,
            null,
            [new EscalationRecipient($recipientId, 'schedule', self::$scheduleId, null)],
            $ruleId
        ));

        $recipients = $this->recipientsOf($db, $id);
        $this->assertCount(1, $recipients, 'The recipient should have been updated, not duplicated');
        $this->assertSame($recipientId, (int) $recipients[0]->id, 'The recipient row must be updated in place');
        $this->assertEquals(self::$scheduleId, $recipients[0]->schedule_id);
        $this->assertNull($recipients[0]->contact_id, 'The former contact_id must be nulled');
        $this->assertNull($recipients[0]->channel_id, 'The channel should have been cleared');
    }
}
