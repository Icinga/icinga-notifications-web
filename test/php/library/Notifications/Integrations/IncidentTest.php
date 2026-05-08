<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Integrations\Incident;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\IncidentContact;
use ipl\Sql\Connection;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class IncidentTest extends TestCase
{
    private ?Connection $previousDatabaseInstance = null;

    /**
     * Snapshots the {@see Database} singleton so each test can swap in a mock {@see Connection} via
     * {@see self::injectDatabase()} without leaking state into the next test.
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

    public function testAddManagerInsertsIncidentContactWhenContactHasNoExistingRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->never())->method('update');
        $db->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) {
                $this->assertSame(42, $data['incident_id']);
                $this->assertSame(7, $data['contact_id']);

                if ($table === 'incident_contact') {
                    $this->assertSame('manager', $data['role']);
                } elseif ($table === 'incident_history') {
                    $this->assertSame('recipient_role_changed', $data['type']);
                    $this->assertSame('manager', $data['new_recipient_role']);
                    $this->assertNull($data['old_recipient_role']);
                } else {
                    $this->fail(sprintf('Unexpected insert into %s', $table));
                }

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('commitTransaction');
        $db->expects($this->never())->method('rollBackTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), null)
            ->addManager(self::contact(7));
    }

    public function testAddManagerUpdatesExistingIncidentContactRole(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $where) {
                $this->assertSame('incident_contact', $table);
                $this->assertSame('manager', $data['role']);
                $this->assertContains(42, $where);
                $this->assertContains(7, $where);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) {
                $this->assertSame('incident_history', $table);
                $this->assertSame(42, $data['incident_id']);
                $this->assertSame(7, $data['contact_id']);
                $this->assertSame('subscriber', $data['old_recipient_role']);
                $this->assertSame('manager', $data['new_recipient_role']);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('subscriber', 7))
            ->addManager(self::contact(7));
    }

    public function testAddManagerIsNoopWhenContactIsAlreadyManager(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');
        $db->expects($this->never())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('manager', 7))
            ->addManager(self::contact(7));
    }

    public function testAddManagerRollsBackOnException(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())
            ->method('insert')
            ->willThrowException(new RuntimeException('boom'));
        $db->expects($this->once())->method('rollBackTransaction');
        $db->expects($this->never())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->expectException(RuntimeException::class);

        $this->incidentFor(self::incidentWithId(42), null)
            ->addManager(self::contact(7));
    }

    public function testAddSubscriberInsertsRowWithSubscriberRole(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) {
                $this->assertSame(42, $data['incident_id']);
                $this->assertSame(7, $data['contact_id']);

                if ($table === 'incident_contact') {
                    $this->assertSame('subscriber', $data['role']);
                } elseif ($table === 'incident_history') {
                    $this->assertSame('recipient_role_changed', $data['type']);
                    $this->assertSame('subscriber', $data['new_recipient_role']);
                    $this->assertNull($data['old_recipient_role']);
                }

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), null)
            ->addSubscriber(self::contact(7));
    }

    public function testAddSubscriberIsNoopWhenContactIsAlreadySubscriber(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('subscriber', 7))
            ->addSubscriber(self::contact(7));
    }

    public function testAddSubscriberDoesNotDemoteAnExistingManager(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('manager', 7))
            ->addSubscriber(self::contact(7));
    }

    public function testRemoveManagerDemotesManagerToSubscriber(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $where) {
                $this->assertSame('incident_contact', $table);
                $this->assertSame('subscriber', $data['role']);
                $this->assertContains(42, $where);
                $this->assertContains(7, $where);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) {
                $this->assertSame('incident_history', $table);
                $this->assertSame(42, $data['incident_id']);
                $this->assertSame(7, $data['contact_id']);
                $this->assertSame('manager', $data['old_recipient_role']);
                $this->assertSame('subscriber', $data['new_recipient_role']);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('manager', 7))
            ->removeManager(self::contact(7));
    }

    public function testRemoveManagerIsNoopWhenContactHasNoIncidentContact(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), null)
            ->removeManager(self::contact(7));
    }

    public function testRemoveManagerIsNoopWhenContactIsSubscriber(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('subscriber', 7))
            ->removeManager(self::contact(7));
    }

    public function testRemoveSubscriberDeletesSubscriberRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) {
                $this->assertSame('incident_contact', $table);
                $this->assertContains(42, $where);
                $this->assertContains(7, $where);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) {
                $this->assertSame('incident_history', $table);
                $this->assertSame(42, $data['incident_id']);
                $this->assertSame(7, $data['contact_id']);
                $this->assertSame('subscriber', $data['old_recipient_role']);
                $this->assertNull($data['new_recipient_role']);

                return $this->createStub(PDOStatement::class);
            });
        $db->expects($this->once())->method('commitTransaction');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('subscriber', 7))
            ->removeSubscriber(self::contact(7));
    }

    public function testRemoveSubscriberIsNoopWhenContactIsNotSubscribed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('delete');
        $db->expects($this->never())->method('insert');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), self::incidentContact('manager', 7))
            ->removeSubscriber(self::contact(7));
    }

    public function testRemoveSubscriberIsNoopWhenContactHasNoIncidentContact(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('delete');

        $this->injectDatabase($db);

        $this->incidentFor(self::incidentWithId(42), null)
            ->removeSubscriber(self::contact(7));
    }

    public function testIsMutedIsTrueWhenIncidentHasMuteReason(): void
    {
        $model = new IncidentModel();
        $model->mute_reason = 'down for maintenance';

        $this->assertTrue((new Incident($model))->isMuted());
    }

    public function testIsMutedIsFalseWhenIncidentHasNoMuteReason(): void
    {
        $model = new IncidentModel();
        $model->mute_reason = null;

        $this->assertFalse((new Incident($model))->isMuted());
    }

    public function testGetSubscribersAsksFetchContactsByRoleForSubscribers(): void
    {
        $a = self::incidentContact('subscriber', 1);
        $b = self::incidentContact('subscriber', 2);

        $incident = $this->incidentReturning(self::incidentWithId(42), 'subscriber', [$a, $b]);

        $this->assertSame([$a, $b], iterator_to_array($incident->getSubscribers(), false));
    }

    public function testGetSubscribersIsEmptyWhenNoSubscribersExist(): void
    {
        $incident = $this->incidentReturning(self::incidentWithId(42), 'subscriber', []);

        $this->assertSame([], iterator_to_array($incident->getSubscribers(), false));
    }

    public function testGetSubscribersIncludesContactgroupAndScheduleEntries(): void
    {
        $contactSub = new IncidentContact();
        $contactSub->role = 'subscriber';
        $contactSub->contact_id = 7;

        $groupSub = new IncidentContact();
        $groupSub->role = 'subscriber';
        $groupSub->contactgroup_id = 8;

        $scheduleSub = new IncidentContact();
        $scheduleSub->role = 'subscriber';
        $scheduleSub->schedule_id = 9;

        $incident = $this->incidentReturning(
            self::incidentWithId(42),
            'subscriber',
            [$contactSub, $groupSub, $scheduleSub]
        );

        $this->assertCount(3, iterator_to_array($incident->getSubscribers(), false));
    }

    public function testGetManagersAsksFetchContactsByRoleForManagers(): void
    {
        $a = self::incidentContact('manager', 1);
        $b = self::incidentContact('manager', 2);

        $incident = $this->incidentReturning(self::incidentWithId(42), 'manager', [$a, $b]);

        $this->assertSame([$a, $b], iterator_to_array($incident->getManagers(), false));
    }

    public function testGetManagersIsEmptyWhenNoManagersExist(): void
    {
        $incident = $this->incidentReturning(self::incidentWithId(42), 'manager', []);

        $this->assertSame([], iterator_to_array($incident->getManagers(), false));
    }

    public function testGetManagersIncludesContactgroupAndScheduleEntries(): void
    {
        $contactMgr = new IncidentContact();
        $contactMgr->role = 'manager';
        $contactMgr->contact_id = 7;

        $groupMgr = new IncidentContact();
        $groupMgr->role = 'manager';
        $groupMgr->contactgroup_id = 8;

        $scheduleMgr = new IncidentContact();
        $scheduleMgr->role = 'manager';
        $scheduleMgr->schedule_id = 9;

        $incident = $this->incidentReturning(
            self::incidentWithId(42),
            'manager',
            [$contactMgr, $groupMgr, $scheduleMgr]
        );

        $this->assertCount(3, iterator_to_array($incident->getManagers(), false));
    }

    private static function incidentContact(string $role, int $contactId): IncidentContact
    {
        $entry = new IncidentContact();
        $entry->role = $role;
        $entry->contact_id = $contactId;

        return $entry;
    }

    private static function contact(int $id): Contact
    {
        $contact = new Contact();
        $contact->id = $id;

        return $contact;
    }

    private static function incidentWithId(int $id): IncidentModel
    {
        $model = new IncidentModel();
        $model->id = $id;

        return $model;
    }

    /**
     * Build an Incident integration whose existing-row lookup is bypassed with the supplied IncidentContact.
     *
     * The override avoids hitting the ORM SELECT path so write-method tests only need to mock
     * Connection::insert/update/delete and the transaction methods. Pass null to model "no existing row".
     */
    private function incidentFor(IncidentModel $model, ?IncidentContact $existing): Incident
    {
        return new class ($model, $existing) extends Incident {
            private ?IncidentContact $existing;

            public function __construct(IncidentModel $incident, ?IncidentContact $existing)
            {
                parent::__construct($incident);
                $this->existing = $existing;
            }

            protected function fetchIncidentContact(Contact $contact): ?IncidentContact
            {
                return $this->existing;
            }
        };
    }

    /**
     * Build an Incident integration whose role lookup is bypassed with the supplied rows.
     *
     * The override yields {@see $rows} when {@see Incident::fetchContactsByRole()} is called with
     * {@see $expectedRole}, and fails the test for any other role. This avoids the ORM SELECT path so
     * reader tests stay self-contained, and verifies the unit-under-test asks for the expected role.
     *
     * @param IncidentContact[] $rows
     */
    private function incidentReturning(IncidentModel $model, string $expectedRole, array $rows): Incident
    {
        return new class ($model, $expectedRole, $rows) extends Incident {
            private string $expectedRole;
            /** @var IncidentContact[] */
            private array $rows;

            public function __construct(IncidentModel $incident, string $expectedRole, array $rows)
            {
                parent::__construct($incident);
                $this->expectedRole = $expectedRole;
                $this->rows = $rows;
            }

            protected function fetchContactsByRole(string $role): Generator
            {
                TestCase::assertSame($this->expectedRole, $role);
                yield from $this->rows;
            }
        };
    }

    /**
     * Replace the {@see Database} singleton with the given connection so the unit under test hits the mock.
     *
     * Restored by tearDown via the snapshot taken in setUp.
     */
    private function injectDatabase(Connection $db): void
    {
        $instance = (new ReflectionClass(Database::class))->getProperty('instance');
        $instance->setValue(null, $db);
    }
}
