<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Form\Data\Source as SourceData;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\SourceRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see SourceRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test performs an operation and reads the result back to verify what
 * was persisted. The schema is created once per driver and not reset between tests, so each test creates the sources
 * (and, where needed, rules) it operates on, using a distinct `listener_username` per test to avoid clashing on its
 * unique constraint.
 */
#[TransactionIsolation]
class SourceRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        // A source has no prerequisites of its own
    }

    /**
     * Insert a source row directly (bypassing the repository) and return its id
     *
     * @param Connection $db
     * @param string $username
     * @param array<string, mixed> $overrides
     *
     * @return int
     */
    private function insertSource(Connection $db, string $username, array $overrides = []): int
    {
        $db->insert('source', array_merge([
            'type'              => 'icingadb',
            'name'              => 'Icinga 2',
            'listener_username' => $username,
            'changed_at'        => (int) (new DateTime())->format('Uv')
        ], $overrides));

        return (int) $db->lastInsertId();
    }

    /**
     * Load a source row directly by id
     *
     * @param Connection $db
     * @param int $id
     *
     * @return ?Source
     */
    private function loadSource(Connection $db, int $id): ?Source
    {
        return Source::on($db)->filter(Filter::equal('source.id', $id))->first();
    }

    public function testTheUsedHashAlgorithmIsStillPhpsDefault(): void
    {
        $this->assertSame(
            PASSWORD_DEFAULT,
            SourceRepository::HASH_ALGORITHM,
            'PHP\'s default password hash algorithm changed. Consider adding support for it'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsTheSource(Connection $db): void
    {
        $id = $this->insertSource($db, 'find-me', ['type' => 'icingadb', 'name' => 'Icinga 2']);

        $source = (new SourceRepository($db))->find($id);

        $this->assertNotNull($source, 'find() did not return the source');
        $this->assertEquals($id, $source->id);
        $this->assertSame('icingadb', $source->type);
        $this->assertSame('Icinga 2', $source->name);
        $this->assertSame('find-me', $source->listener_username);
        $this->assertFalse($source->deleted, 'The deleted flag should be cast to a bool');
        $this->assertFalse($source->locked, 'The locked flag should be cast to a bool');
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheSourceDoesNotExist(Connection $db): void
    {
        $this->assertNull((new SourceRepository($db))->find(404));
    }

    #[DataProvider('sharedDatabases')]
    public function testFindByUsernameReturnsTheSource(Connection $db): void
    {
        $id = $this->insertSource($db, 'by-name');

        $source = (new SourceRepository($db))->findByUsername('by-name');

        $this->assertNotNull($source, 'findByUsername() did not return the source');
        $this->assertEquals($id, $source->id);
        $this->assertSame('by-name', $source->listener_username);
    }

    #[DataProvider('sharedDatabases')]
    public function testFindByUsernameReturnsNullIfNotFound(Connection $db): void
    {
        $this->assertNull((new SourceRepository($db))->findByUsername('nobody'));
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheSource(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(new SourceData(
            null,
            'icingadb',
            'Icinga 2',
            'create-plain',
            null,
            null,
            false
        ));

        $stored = $this->loadSource($db, $sourceId);
        $this->assertNotNull($stored, 'The created source was not found');
        $this->assertSame('icingadb', $stored->type);
        $this->assertSame('Icinga 2', $stored->name);
        $this->assertFalse($stored->deleted);
        $this->assertFalse($stored->locked);
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresALockedSource(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(new SourceData(
            null,
            'icingadb',
            'Managed',
            'create-locked',
            null,
            null,
            true
        ));

        $stored = $this->loadSource($db, $sourceId);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->locked, 'The locked flag should have been persisted');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateHashesThePasswordBeforeStoring(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(new SourceData(
            null,
            'icingadb',
            'Src',
            'create-hash',
            'mysecret',
            null,
            false
        ));

        $stored = $this->loadSource($db, $sourceId);
        $this->assertNotNull($stored->listener_password_hash, 'A password hash should have been stored');
        $this->assertNotSame('mysecret', $stored->listener_password_hash, 'The password must not be stored in clear');
        $this->assertTrue(
            password_verify('mysecret', $stored->listener_password_hash),
            'The stored hash must verify against the given password'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateClearsThePlaintextPasswordAfterHashing(Connection $db): void
    {
        $data = new SourceData(
            null,
            'icingadb',
            'Src',
            'create-clear',
            'mysecret',
            null,
            false
        );

        (new SourceRepository($db))->create($data);

        $this->assertNull($data->listenerPassword, 'The plaintext password must be cleared after hashing');
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesTheSource(Connection $db): void
    {
        $id = $this->insertSource($db, 'update-name', ['name' => 'Old Name']);

        (new SourceRepository($db))->update(new SourceData(
            $id,
            'icingadb',
            'Renamed',
            'update-name',
            null,
            null,
            false
        ));

        $stored = $this->loadSource($db, $id);
        $this->assertSame('Renamed', $stored->name);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheSourceHasNoId(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceRepository($db))->update(new SourceData(
            null,
            'icingadb',
            'Src',
            'update-noid',
            null,
            null,
            false
        ));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheSourceDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceRepository($db))->update(new SourceData(
            999,
            'icingadb',
            'Src',
            'update-missing',
            null,
            null,
            false
        ));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateHashesThePasswordBeforeStoring(Connection $db): void
    {
        $id = $this->insertSource($db, 'update-hash');

        (new SourceRepository($db))->update(new SourceData(
            $id,
            'icingadb',
            'Src',
            'update-hash',
            'newsecret',
            null,
            false
        ));

        $stored = $this->loadSource($db, $id);
        $this->assertTrue(
            password_verify('newsecret', $stored->listener_password_hash),
            'The stored hash must verify against the new password'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateClearsThePlaintextPasswordAfterHashing(Connection $db): void
    {
        $id = $this->insertSource($db, 'update-clear');
        $data = new SourceData(
            $id,
            'icingadb',
            'Src',
            'update-clear',
            'newsecret',
            null,
            false
        );

        (new SourceRepository($db))->update($data);

        $this->assertNull($data->listenerPassword, 'The plaintext password must be cleared after hashing');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheSource(Connection $db): void
    {
        $id = $this->insertSource($db, 'doomed');

        (new SourceRepository($db))->delete($id);

        // It's only soft-deleted: the row still exists, flagged deleted with its username freed
        $stored = $this->loadRawEntity($db, $id, Source::class);
        $this->assertNotNull($stored, 'The source row should still exist');
        $this->assertSame('y', $stored->deleted, 'The source should be soft-deleted, not removed');
        $this->assertNull($stored->listener_username, 'The unique listener_username should be nulled on deletion');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheSourceDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceRepository($db))->delete(999);
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesLinkedRulesOnlyIfTheSourceIsTheLastOfItsType(Connection $db): void
    {
        $sourceId = $this->insertSource($db, 'icingadb1');
        $sourceId2 = $this->insertSource($db, 'icingadb2');
        $db->insert('rule', [
            'name' => 'Linked Rule', 'source_type' => 'icingadb', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);
        $ruleId = (int) $db->lastInsertId();
        $db->insert('rule', [
            'name' => 'Unaffected Rule', 'source_type' => 'test', 'changed_at' => (int) (new DateTime())->format('Uv')
        ]);
        $unaffectedRuleId = (int) $db->lastInsertId();

        (new SourceRepository($db))->delete($sourceId);

        $rule = $this->loadRawEntity($db, $ruleId, Rule::class);
        $this->assertSame('n', $rule->deleted, 'The rule must not be deleted if a source of its type still exists');

        (new SourceRepository($db))->delete($sourceId2);

        // The linked rule is soft-deleted along with last source of its type
        $rule = $this->loadRawEntity($db, $ruleId, Rule::class);
        $this->assertNotNull($rule, 'The rule row should still exist');
        $this->assertSame(
            'y',
            $rule->deleted,
            'The rule must be soft-deleted when the last source of its type is deleted'
        );

        $this->assertSame(
            'n',
            $this->loadRawEntity($db, $unaffectedRuleId, Rule::class)->deleted,
            'Rules of other sources must not be affected'
        );

        $this->assertSame(
            'y',
            $this->loadRawEntity($db, $sourceId, Source::class)->deleted,
            'The source itself must be soft-deleted'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testCreatePersistsClientCertificateSubject(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(
            new SourceData(
                null,
                'icingadb',
                'Icinga 2',
                null,
                null,
                'CN=source.example.com',
                false
            )
        );

        $source = $this->loadSource($db, $sourceId);
        $this->assertNotNull($source, 'The created source was not found');
        $this->assertNull($source->listener_username, 'The unique listener_username is not null');
        $this->assertSame(
            'CN=source.example.com',
            $source->client_certificate_subject,
            'The client certificate subject is not persisted'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteNullsClientCertificateSubject(Connection $db): void
    {
        $id = $this->insertSource($db, '', [
            'listener_username' => null,
            'client_certificate_subject' => 'CN=source.example.com'
        ]);

        (new SourceRepository($db))->delete($id);

        $source = $this->loadRawEntity($db, $id, Source::class);
        $this->assertNotNull($source, 'The source row should still exist');
        $this->assertNull(
            $source->client_certificate_subject,
            'The client certificate subject should be nulled on deletion'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateNullsListenerConfigOnSwitchToClientCertificateSubject(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(
            new SourceData(
                null,
                'icingadb',
                'Icinga 2',
                'icingadb',
                'icingadb',
                null,
                false
            )
        );

        (new SourceRepository($db))->update(
            new SourceData(
                $sourceId,
                'icingadb',
                'Icinga 2',
                null,
                null,
                'CN=source.example.com',
                false
            )
        );

        $source = $db->select(
            (new Select())
                ->from('source')
                ->columns(['listener_username', 'listener_password_hash', 'client_certificate_subject'])
                ->where(['id = ?' => $sourceId])
        )->fetch();
        $this->assertNull($source['listener_username'], 'The unique listener_username is not null');
        $this->assertNull($source['listener_password_hash'], 'The unique listener_password_hash is not null');
        $this->assertNotNull(
            $source['client_certificate_subject'],
            'The unique client_certificate_subject is still null'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateNullsClientCertificateSubjectOnSwitchToListenerConfig(Connection $db): void
    {
        $sourceId = (new SourceRepository($db))->create(
            new SourceData(
                null,
                'icingadb',
                'Icinga 2',
                null,
                null,
                'CN=source.example.com',
                false
            )
        );

        (new SourceRepository($db))->update(
            new SourceData(
                $sourceId,
                'icingadb',
                'Icinga 2',
                'icingadb',
                'icingadb',
                null,
                false
            )
        );

        $source = $db->select(
            (new Select())
                ->from('source')
                ->columns(['listener_username', 'listener_password_hash', 'client_certificate_subject'])
                ->where(['id = ?' => $sourceId])
        )->fetch();
        $this->assertNotNull($source['listener_username'], 'The unique listener_username is still null');
        $this->assertNotNull($source['listener_password_hash'], 'The unique listener_password_hash is still null');
        $this->assertNull($source['client_certificate_subject'], 'The unique client_certificate_subject is not null');
    }
}
