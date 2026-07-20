<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\SourceRepository;
use ipl\Sql\Connection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SourceRepository}.
 *
 * No real database is used. A {@see Connection} mock is used and the statements the repository is expected to issue
 * are anticipated and verified. ORM-driven SELECT queries are served by returning real {@see PDOStatement}s (backed
 * by an in-memory SQLite database) whose rows the ORM hydrates into models.
 *
 * {@see SourceRepository} delegates writes to {@see \Icinga\Module\Notifications\Common\EntityManager}, which wraps
 * every save in a transaction. The mock's `transaction()` is wired to invoke the callback so that the EntityManager's
 * insert/update calls reach the mock's expectations.
 *
 * What these tests do not cover:
 * - The actual interaction with a production database.
 * - The rendered SQL of the issued statements (the connection is mocked before rendering happens).
 */
class SourceRepositoryTest extends TestCase
{
    /**
     * Build a real PDOStatement yielding the given rows.
     *
     * The ORM's Hydrator consumes the PDOStatement returned by the mocked `select()`. Feeding it a
     * real statement (even one backed by an in-memory SQLite) gives the Hydrator a genuine cursor to
     * iterate and lets it map column values to model properties normally.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return PDOStatement
     */
    private function selectResult(array $rows): PDOStatement
    {
        $columns = empty($rows) ? ['id'] : array_keys($rows[0]);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $pdo->exec('CREATE TABLE result (' . implode(', ', array_map(fn($c) => '"' . $c . '"', $columns)) . ')');

        if (! empty($rows)) {
            $insert = $pdo->prepare(
                'INSERT INTO result VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            foreach ($rows as $row) {
                $insert->execute(array_values($row));
            }
        }

        return $pdo->query('SELECT * FROM result');
    }

    public function testWhetherTheUsedHashAlgorithmIsStillTheDefault(): void
    {
        $this->assertSame(
            PASSWORD_DEFAULT,
            SourceRepository::HASH_ALGORITHM,
            'PHP\'s default password hash algorithm changed. Consider adding support for it'
        );
    }

    public function testFindHydratesTheSource(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([
                [
                    'id'               => 5,
                    'type'             => 'icingadb',
                    'name'             => 'Icinga 2',
                    'listener_username' => 'listener',
                    'deleted'          => 'n',
                    'locked'           => 'n'
                ]
            ]));

        $source = (new SourceRepository($db))->find(5);

        $this->assertNotNull($source, 'find() did not return the source');
        $this->assertEquals(5, $source->id);
        $this->assertSame('icingadb', $source->type);
        $this->assertSame('Icinga 2', $source->name);
        $this->assertSame('listener', $source->listener_username);
        $this->assertFalse($source->deleted, 'The deleted flag should be cast to a bool');
        $this->assertFalse($source->locked, 'The locked flag should be cast to a bool');
    }

    public function testFindReturnsNullIfSourceDoesNotExist(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $this->assertNull((new SourceRepository($db))->find(404));
    }

    public function testFindByUsernameHydratesTheSource(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([
                [
                    'id'               => 5,
                    'type'             => 'icingadb',
                    'name'             => 'Icinga 2',
                    'listener_username' => 'alice',
                    'deleted'          => 'n',
                    'locked'           => 'n'
                ]
            ]));

        $source = (new SourceRepository($db))->findByUsername('alice');

        $this->assertNotNull($source, 'findByUsername() did not return the source');
        $this->assertEquals(5, $source->id);
        $this->assertSame('alice', $source->listener_username);
    }

    public function testFindByUsernameReturnsNullIfNotFound(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $this->assertNull((new SourceRepository($db))->findByUsername('nobody'));
    }

    public function testCreateInsertsSourceAndAssignsId(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $source = new Source([
            'type'              => 'icingadb',
            'name'              => 'Icinga 2',
            'listener_username' => 'listener'
        ]);
        $source->setNew();

        $db = $this->getConnectionMock();
        $db->expects($this->never())->method('update');
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start) {
                $this->assertSame('source', $table);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(
                    ['type' => 'icingadb', 'name' => 'Icinga 2', 'listener_username' => 'listener'],
                    $data
                );

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('lastInsertId')->willReturn('42');

        (new SourceRepository($db))->create($source);

        $this->assertEquals(42, $source->id, 'The generated id was not assigned to the source');
    }

    public function testCreatePersistsClientCertificateSubject(): void
    {
        $source = new Source([
            'type'                       => 'icingadb',
            'name'                       => 'Icinga 2',
            'client_certificate_subject' => 'CN=source.example.com'
        ]);
        $source->setNew();

        $db = $this->getConnectionMock();
        $db->expects($this->never())->method('update');
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($_, $data) {
                $this->assertSame('CN=source.example.com', $data['client_certificate_subject']);
                $this->assertArrayNotHasKey('listener_username', $data);
                $this->assertArrayNotHasKey('listener_password_hash', $data);

                return $this->createStub(PDOStatement::class);
            });
        $db->method('lastInsertId')->willReturn('1');

        (new SourceRepository($db))->create($source);
    }

    public function testCreateHashesPasswordBeforeInserting(): void
    {
        $source = new Source(['type' => 'icingadb', 'name' => 'Src']);
        $source->listener_password = 'mysecret';
        $source->setNew();

        $db = $this->getConnectionMock();
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($_, $data) {
                $this->assertArrayHasKey('listener_password_hash', $data);
                $this->assertNotSame('mysecret', $data['listener_password_hash']);
                $this->assertTrue(
                    password_verify('mysecret', $data['listener_password_hash']),
                    'The insert data must carry a valid bcrypt hash of the password'
                );

                return $this->createStub(PDOStatement::class);
            });
        $db->method('lastInsertId')->willReturn('1');

        (new SourceRepository($db))->create($source);
    }

    public function testUpdateUpdatesTheSource(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $source = new Source([
            'id'   => 5,
            'type' => 'icingadb',
            'name' => 'Old Name'
        ]);
        $source->setNew(false);
        $source->name = 'Renamed';

        $db = $this->getConnectionMock();
        $db->expects($this->never())->method('insert');
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('source', $table);
                $this->assertSame(['id = ?' => 5], $where);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(['name' => 'Renamed'], $data);

                return $this->createStub(PDOStatement::class);
            });

        (new SourceRepository($db))->update($source);
    }

    public function testUpdateHashesPasswordBeforeUpdating(): void
    {
        $source = new Source(['id' => 5, 'type' => 'icingadb', 'name' => 'Src']);
        $source->setNew(false);
        $source->listener_password = 'newsecret';

        $db = $this->getConnectionMock();
        $db->expects($this->never())->method('insert');
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($_, $data) {
                $this->assertArrayHasKey('listener_password_hash', $data);
                $this->assertTrue(
                    password_verify('newsecret', $data['listener_password_hash']),
                    'The update data must carry a valid bcrypt hash of the new password'
                );

                return $this->createStub(PDOStatement::class);
            });

        (new SourceRepository($db))->update($source);
    }

    /**
     * Covers deleting a source that has no rules, so only the source row itself is soft-deleted.
     *
     * Three selects are expected: one for {@see SourceRepository::find()}, one for the lazy-loaded
     * `rule` relation that {@see SourceRepository::delete()} iterates (returns no rows, so
     * {@see \Icinga\Module\Notifications\Forms\EventRuleConfigForm::removeRule()} is never called), and
     * one for {@see EntityManager::stampChangedAt()}'s `MAX(changed_at)` read, which fires when the
     * source itself is persisted via {@see EntityManager::save()}. The latter is unrelated to the rule
     * cascade this test covers, but is triggered as a side effect of every `EntityManager::save()` call
     * on a model with a `changed_at` column.
     *
     * @return void
     */
    public function testDeleteMarksSourceDeleted(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $db = $this->getConnectionMock();
        $db->expects($this->exactly(3))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                $this->selectResult([
                    [
                        'id'               => 5,
                        'type'             => 'icingadb',
                        'name'             => 'Icinga 2',
                        'listener_username' => 'u',
                        'deleted'          => 'n',
                        'locked'           => 'n'
                    ]
                ]),
                $this->selectResult([]), // no rules — EventRuleConfigForm::removeRule is never called
                $this->selectResult([['changed_at' => 0]]) // EntityManager::stampChangedAt()'s MAX(changed_at)
            );
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('source', $table);
                $this->assertSame(['id = ?' => '5'], $where);
                $this->assertSame('y', $data['deleted']);
                $this->assertNull($data['listener_username']);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);

                return $this->createStub(PDOStatement::class);
            });

        $repo = new SourceRepository($db);
        $repo->delete($repo->find(5));
    }

    /**
     * Covers deleting a source that has a linked rule: {@see EventRuleConfigForm::removeRule()} must be
     * called for each rule, soft-deleting the rule and its escalations before the source itself is deleted.
     *
     * Four selects are expected: one for {@see SourceRepository::find()}, one for the lazy-loaded `rule`
     * relation (returns one rule), one for the lazy-loaded `rule_escalation` relation inside
     * {@see EventRuleConfigForm::removeRule()} (returns no escalations to keep the test focused), and one
     * for {@see EntityManager::stampChangedAt()}'s `MAX(changed_at)` read, which fires when the source
     * itself is persisted via {@see EntityManager::save()}. The latter is unrelated to the rule/escalation
     * cascade this test actually covers, but is triggered as a side effect of every `EntityManager::save()`
     * call on a model with a `changed_at` column.
     *
     * @return void
     */
    public function testDeleteRemovesLinkedRules(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->exactly(4))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                $this->selectResult([
                    [
                        'id'               => 5,
                        'type'             => 'icingadb',
                        'name'             => 'Icinga 2',
                        'listener_username' => 'u',
                        'deleted'          => 'n',
                        'locked'           => 'n'
                    ]
                ]),
                $this->selectResult([
                    ['id' => 7]
                ]),
                $this->selectResult([]), // no escalations
                $this->selectResult([['changed_at' => 0]]) // EntityManager::stampChangedAt()'s MAX(changed_at)
            );

        $updatedTables = [];
        $db->expects($this->exactly(3))
            ->method('update')
            ->willReturnCallback(function ($table) use (&$updatedTables) {
                $updatedTables[] = $table;

                return $this->createStub(PDOStatement::class);
            });

        $repo = new SourceRepository($db);
        $repo->delete($repo->find(5));

        $this->assertContains('rule', $updatedTables, 'The linked rule must be soft-deleted');
        $this->assertContains('source', $updatedTables, 'The source itself must be soft-deleted');
    }

    /**
     * Covers deleting a certificate-based source: {@see SourceRepository::delete()} must null the
     * `client_certificate_subject` alongside the `listener_username`, so the soft-deleted row keeps no
     * identity and frees the unique certificate subject for reuse.
     *
     * The fixture carries a non-null subject on purpose: the {@see EntityManager} only emits columns that
     * actually change, so a source loaded without a subject would never surface it in the UPDATE.
     *
     * Three selects are expected: {@see SourceRepository::find()}, the lazy-loaded `rule` relation (no rows),
     * and {@see EntityManager::stampChangedAt()}'s `MAX(changed_at)` read triggered by the save.
     *
     * @return void
     */
    public function testDeleteNullsClientCertificateSubject(): void
    {
        $db = $this->getConnectionMock();
        $db->expects($this->exactly(3))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                $this->selectResult([
                    [
                        'id'                         => 5,
                        'type'                       => 'icingadb',
                        'name'                       => 'Icinga 2',
                        'client_certificate_subject' => 'CN=source.example.com',
                        'deleted'                    => 'n',
                        'locked'                     => 'n'
                    ]
                ]),
                $this->selectResult([]), // no rules
                $this->selectResult([['changed_at' => 0]]) // EntityManager::stampChangedAt()'s MAX(changed_at)
            );
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($_, $data) {
                $this->assertSame('y', $data['deleted']);
                $this->assertNull($data['client_certificate_subject']);

                return $this->createStub(PDOStatement::class);
            });

        $repo = new SourceRepository($db);
        $repo->delete($repo->find(5));
    }

    public function testCreateClearsPlaintextPasswordAfterHashing(): void
    {
        $source = new Source(['type' => 'icingadb', 'name' => 'Src']);
        $source->listener_password = 'mysecret';
        $source->setNew();

        $db = $this->getConnectionMock();
        $db->method('insert')->willReturn($this->createStub(PDOStatement::class));
        $db->method('lastInsertId')->willReturn('1');

        (new SourceRepository($db))->create($source);

        $this->assertFalse(isset($source->listener_password), 'listener_password must be unset after hashing');
    }

    public function testUpdateClearsPlaintextPasswordAfterHashing(): void
    {
        $source = new Source(['id' => 5, 'type' => 'icingadb', 'name' => 'Src']);
        $source->setNew(false);
        $source->listener_password = 'newsecret';

        $db = $this->getConnectionMock();
        $db->method('update')->willReturn($this->createStub(PDOStatement::class));

        (new SourceRepository($db))->update($source);

        $this->assertFalse(isset($source->listener_password), 'listener_password must be unset after hashing');
    }

    private function getConnectionMock(): Connection&MockObject
    {
        $db = $this->createMock(Connection::class);
        $db->method('transaction')->willReturnCallback(fn(callable $cb) => $cb());
        $db->method('quoteIdentifier')->willReturnCallback(fn($s) => $s);

        return $db;
    }
}
