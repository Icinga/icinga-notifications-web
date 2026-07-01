<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use ArrayIterator;
use DateTime;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use Icinga\Module\Notifications\Repository\ContactRepository;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ContactRepository}.
 *
 * Like {@see RotationRepositoryTest}, these tests don't talk to a real database. A {@see Connection} mock is used and
 * the statements the repository is expected to issue are anticipated and verified. The ORM queries the repository
 * builds internally are served by returning real {@see PDOStatement}s (backed by an in-memory SQLite database) whose
 * rows the ORM hydrates into models.
 *
 * What these tests do not cover:
 * - The actual interaction with a production database, which is mocked.
 * - The rendered SQL of the issued statements (the connection is mocked before rendering happens).
 * - The handling of the contact's rotations on deletion, which is delegated to (and covered by)
 *   {@see RotationRepositoryTest}; the contacts used here have no rotations.
 */
class ContactRepositoryTest extends TestCase
{
    /**
     * Build a real PDOStatement yielding the given rows
     *
     * See {@see RotationRepositoryTest::selectResult()} for the rationale.
     *
     * @param list<array<string, mixed>> $rows All rows must share the same keys, which become the result's columns
     *
     * @return PDOStatement
     */
    private function selectResult(array $rows): PDOStatement
    {
        $columns = empty($rows) ? ['id'] : array_keys($rows[0]);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $pdo->exec('CREATE TABLE result (' . implode(', ', array_map(fn ($c) => '"' . $c . '"', $columns)) . ')');

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

    /**
     * Build a contact address
     *
     * @param array<string, mixed> $properties
     *
     * @return ContactAddress
     */
    private function contactAddress(array $properties): ContactAddress
    {
        return (new ContactAddress())->setProperties($properties);
    }

    /**
     * Build the contact's rotation relation as a query that expects to be iterated
     *
     * The deletion has to consider the contact's rotations, so the relation must at least be iterated. The query is
     * therefore a mock asserting `getIterator()` is called; it yields no rotations here, keeping the involvement of
     * {@see \Icinga\Module\Notifications\Repository\RotationRepository} (covered separately) out of these tests.
     *
     * @return Query
     */
    private function expectIteratedRotations(): Query
    {
        $rotations = $this->createMock(Query::class);
        $rotations->expects($this->once())
            ->method('getIterator')
            ->willReturn(new ArrayIterator([]));

        return $rotations;
    }

    /**
     * Covers fetching a contact, anticipating the `select` it issues and providing a row the ORM hydrates into the
     * returned model.
     *
     * @return void
     */
    public function testFindHydratesTheContact(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn($this->selectResult([
                [
                    'id'            => 7,
                    'full_name'     => 'John Doe',
                    'username'      => 'jdoe',
                    'deleted'       => 'n'
                ]
            ]));

        $contact = (new ContactRepository($databaseMock))->find(7);

        $this->assertNotNull($contact, 'find() did not return the contact');
        $this->assertEquals(7, $contact->id);
        $this->assertSame('John Doe', $contact->full_name);
        $this->assertSame('jdoe', $contact->username);
        $this->assertFalse($contact->deleted, 'The deleted flag should be cast to a bool');
    }

    /**
     * Covers fetching a non-existent contact, which is expected to yield null.
     *
     * @return void
     */
    public function testFindReturnsNullIfTheContactDoesNotExist(): void
    {
        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([]));

        $this->assertNull((new ContactRepository($databaseMock))->find(404));
    }

    /**
     * Covers creating a contact with two addresses: the contact is inserted (with a generated UUID), its id is set on
     * the model, and an address is inserted for each of its addresses.
     *
     * @return void
     */
    public function testCreateInsertsContactWithAddresses(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $contact = (new Contact())->setProperties([
            'full_name'         => 'John Doe',
            'username'          => 'jdoe',
            'contact_address'   => [
                $this->contactAddress(['type' => 'email', 'address' => 'jdoe@example.com']),
                $this->contactAddress(['type' => 'rocketchat', 'address' => '@jdoe'])
            ]
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('update');

        $addresses = [];
        $databaseMock->expects($this->exactly(3))
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start, &$addresses) {
                $this->assertArrayHasKey('changed_at', $data, sprintf('Insert into %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'contact') {
                    $this->assertArrayHasKey('external_uuid', $data);
                    $this->assertMatchesRegularExpression(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                        $data['external_uuid'],
                        'A v4 UUID should be generated for the contact'
                    );
                    unset($data['external_uuid']);
                    $this->assertSame(
                        [
                            'full_name'             => 'John Doe',
                            'username'              => 'jdoe',
                            'default_channel_id'    => null,
                            'deleted'               => 'n'
                        ],
                        $data
                    );
                } elseif ($table === 'contact_address') {
                    // The contact's generated id (see lastInsertId() below) must be referenced
                    $this->assertSame(50, (int) $data['contact_id']);
                    unset($data['contact_id']);
                    $this->assertSame('n', $data['deleted']);
                    $addresses[$data['type']] = $data['address'];
                } else {
                    $this->fail(sprintf('Unexpected insert into %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        $databaseMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('50');

        (new ContactRepository($databaseMock))->create($contact);

        $this->assertSame('50', $contact->id, 'The generated id was not assigned to the contact');
        $this->assertSame(
            ['email' => 'jdoe@example.com', 'rocketchat' => '@jdoe'],
            $addresses,
            'Both addresses should have been inserted with their respective values'
        );
    }

    /**
     * Covers updating a contact and the differential update of its addresses: an existing address that's still
     * required is updated, one that's no longer required is soft-deleted, and a newly required one is inserted.
     *
     * @return void
     */
    public function testUpdatePerformsDifferentialAddressUpdate(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $contact = (new Contact())->setProperties([
            'id'                => 7,
            'full_name'         => 'Jane Doe',
            'username'          => 'jane',
            'contact_address'   => [
                // Kept (existing) - its address changed
                $this->contactAddress(['type' => 'email', 'address' => 'new@example.com']),
                // Added (not present yet)
                $this->contactAddress(['type' => 'sms', 'address' => '12345'])
            ]
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        // The addresses currently stored for the contact
        $databaseMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectResult([
                ['id' => 1, 'contact_id' => 7, 'type' => 'email', 'address' => 'old@example.com', 'deleted' => 'n'],
                ['id' => 2, 'contact_id' => 7, 'type' => 'rocketchat', 'address' => '@jane', 'deleted' => 'n']
            ]));

        // One insert for the newly required 'sms' address
        $databaseMock->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start) {
                $this->assertSame('contact_address', $table);
                $this->assertArrayHasKey('changed_at', $data);
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);
                $this->assertSame(
                    [
                        'contact_id'    => 7,
                        'type'          => 'sms',
                        'address'       => '12345',
                        'deleted'       => 'n'
                    ],
                    $data
                );

                return $this->createStub(PDOStatement::class);
            });

        $seen = [];
        $databaseMock->expects($this->exactly(3))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$seen) {
                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'contact') {
                    $seen[] = 'contact';
                    $this->assertSame(['id' => 7], $where);
                    $this->assertSame(
                        ['full_name' => 'Jane Doe', 'username' => 'jane', 'default_channel_id' => null],
                        $data
                    );
                } elseif ($table === 'contact_address' && ($data['deleted'] ?? null) === 'y') {
                    // The 'rocketchat' address is no longer required and gets soft-deleted
                    // (the id is loosely compared as it stems from the SQLite-backed hydration)
                    $seen[] = 'address-delete';
                    $this->assertEquals(['id = ?' => 2, 'deleted' => 'n'], $where);
                    $this->assertSame(['deleted' => 'y'], $data);
                } elseif ($table === 'contact_address') {
                    // The 'email' address is still required and gets its (changed) address updated
                    $seen[] = 'address-update';
                    $this->assertEquals(['id = ?' => 1, 'deleted' => 'n'], $where);
                    $this->assertSame(['address' => 'new@example.com'], $data);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new ContactRepository($databaseMock))->update($contact);

        $this->assertSame(['contact', 'address-update', 'address-delete'], $seen);
    }

    /**
     * Covers deleting a contact (without rotations) whose recipient references aren't shared with other escalations.
     * The contact, its addresses, group memberships and recipient references are soft-deleted, no escalation is
     * touched.
     *
     * @return void
     */
    public function testDeleteSoftDeletesContactAndReferences(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $contact = (new Contact())->setProperties([
            'id'        => 7,
            // The relation is iterated (asserted), but yields no rotations, so RotationRepository isn't involved
            'rotation'  => $this->expectIteratedRotations()
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('insert');

        // No escalation references the contact, so the second fetchCol is skipped entirely
        $databaseMock->expects($this->once())
            ->method('fetchCol')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn([]);

        $tables = [];
        $databaseMock->expects($this->exactly(4))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$tables) {
                $tables[] = $table;

                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'rule_escalation_recipient') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contactgroup_member') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contact_address') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contact') {
                    $this->assertSame(['username' => null, 'deleted' => 'y'], $data);
                    $this->assertSame(['id' => 7, 'deleted' => 'n'], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new ContactRepository($databaseMock))->delete($contact);

        $this->assertSame(
            ['rule_escalation_recipient', 'contactgroup_member', 'contact_address', 'contact'],
            $tables
        );
    }

    /**
     * Covers deleting a contact whose recipient references leave an escalation without any other recipients. That
     * escalation is expected to be removed as well.
     *
     * @return void
     */
    public function testDeleteAlsoRemovesEscalationsLeftWithoutRecipients(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $contact = (new Contact())->setProperties([
            'id'        => 7,
            'rotation'  => $this->expectIteratedRotations()
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->never())
            ->method('insert');

        // First the escalations referenced by the contact's recipients (5 and 6), then those among them that still
        // have other recipients (only 6). Escalation 5 is thus left without recipients and must be removed.
        $databaseMock->expects($this->exactly(2))
            ->method('fetchCol')
            ->willReturnOnConsecutiveCalls([5, 6], [6]);

        $tables = [];
        $databaseMock->expects($this->exactly(5))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start, &$tables) {
                $tables[] = $table;

                $this->assertArrayHasKey('changed_at', $data, sprintf('Update of %s has no changed_at', $table));
                $this->assertGreaterThanOrEqual($start, $data['changed_at']);
                unset($data['changed_at']);

                if ($table === 'rule_escalation') {
                    $this->assertSame(['position' => null, 'deleted' => 'y'], $data);
                    $this->assertSame(['id IN (?)' => [5]], $where);
                } elseif ($table === 'rule_escalation_recipient') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contactgroup_member') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contact_address') {
                    $this->assertSame(['deleted' => 'y'], $data);
                    $this->assertSame(['contact_id' => 7, 'deleted' => 'n'], $where);
                } elseif ($table === 'contact') {
                    $this->assertSame(['username' => null, 'deleted' => 'y'], $data);
                    $this->assertSame(['id' => 7, 'deleted' => 'n'], $where);
                } else {
                    $this->fail(sprintf('Unexpected update of %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });

        (new ContactRepository($databaseMock))->delete($contact);

        // The escalation cleanup happens right after the recipient references are removed
        $this->assertSame(
            ['rule_escalation_recipient', 'rule_escalation', 'contactgroup_member', 'contact_address', 'contact'],
            $tables
        );
    }
}
