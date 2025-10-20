<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Tests\Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\ConfigProviderInterface;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Sql\Connection;
use ipl\Web\Url;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class EventRuleConfigFormTest extends TestCase
{
    public function testRequiresAnEscalationWithOneRecipient(): void
    {
        $providerMock = $this->createMock(ConfigProviderInterface::class);

        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([]);

        $requestStub = $this->createStub(ServerRequestInterface::class);
        $requestStub->method('getMethod')->willReturn('POST');
        $requestStub->method('getUploadedFiles')->willReturn([]);
        $requestStub->method('getParsedBody')->willReturn([
            'id' => 1337,
            'name' => 'Test'
        ]);

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->handleRequest($requestStub);

        $elements = $form->getElements();
        $this->assertNotEmpty($elements, 'Form has no elements');

        // Form must be invalid for one reason only, the escalations
        foreach ($elements as $element) {
            if ($element->getName() === 'escalations') {
                $this->assertFalse($element->isValid(), 'Escalations are not required');
                $this->assertTrue($element->hasElement('0'), 'At least one escalation is required');
                $escalation = $element->getElement('0');
                $this->assertFalse($escalation->isValid(), 'The escalation is not required to have recipients');
                $this->assertTrue($escalation->hasElement('recipients'), 'The escalation has no recipients');
                $recipients = $escalation->getElement('recipients');
                $this->assertFalse($recipients->isValid(), 'The escalation does not require recipients');
                $this->assertTrue($recipients->hasElement('0'), 'At least one recipient is required');
                $recipient = $recipients->getElement('0');
                $this->assertFalse($recipient->isValid(), 'The escalation recipient is not required');
            } else {
                $this->assertTrue($element->isValid(), sprintf('Element %s is not valid', $element->getName()));
            }
        }
    }

    /**
     * Tests the process of loading, mocking, and storing rule escalations and related entities into the database.
     *
     * This method verifies the following operations:
     * - Mocking and fetching contacts, contact groups, schedules, and channels.
     * - Generating rule escalations and their corresponding recipients using mock data.
     * - Inserting and updating rule escalations and recipients in the database with proper assertions.
     * - Handling deletion conditions and verifying database operations.
     *
     * What this test does not cover:
     * - The actual interaction with the database, which is mocked.
     * - The actual handling of form submissions, which is simulated.
     * - The insertion and deletion of entire rules.
     *
     * @return void
     */
    public function testLoadAndStorage(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $providerMock = $this->createMock(ConfigProviderInterface::class);
        $providerMock->expects($this->exactly(3))
            ->method('fetchContacts')
            ->willReturn([
                (new Contact())->setProperties(['id' => 1, 'full_name' => 'Test User 1']),
                (new Contact())->setProperties(['id' => 2, 'full_name' => 'Test User 2'])
            ]);
        $providerMock->expects($this->exactly(3))
            ->method('fetchContactGroups')
            ->willReturn([
                (new Contactgroup())->setProperties(['id' => 1, 'name' => 'Test Group'])
            ]);
        $providerMock->expects($this->exactly(3))
            ->method('fetchSchedules')
            ->willReturn([
                (new Schedule())->setProperties(['id' => 1, 'name' => 'Test Schedule'])
            ]);
        $providerMock->expects($this->exactly(3))
            ->method('fetchChannels')
            ->willReturn([
                (new Channel())->setProperties(['id' => 1, 'name' => 'Test Channel'])
            ]);

        $dbRule = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => 'servicegroup.name=Test%20Group',
            'timeperiod_id' => null,
            'rule_escalation' => [
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'position' => 0,
                    'condition' => 'incident_age>=5m',
                    'rule_escalation_recipient' => [
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 1,
                            'contact_id' => 1,
                            'contactgroup_id' => null,
                            'schedule_id' => null,
                            'channel_id' => null
                        ]),
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 2
                        ])
                    ]
                ]),
                (new RuleEscalation())->setProperties(['id' => 2])
            ]
        ]);

        $firstRuleEscalationRecipientMock = $this->createMock(Query::class);
        $firstRuleEscalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => 1,
                    'contact_id' => 1,
                    'contactgroup_id' => null,
                    'schedule_id' => null,
                    'channel_id' => 1
                ])
            ]);

        $secondRuleEscalationRecipientMock = $this->createMock(Query::class);
        $secondRuleEscalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => null,
                    'contact_id' => null,
                    'contactgroup_id' => 1,
                    'schedule_id' => null,
                    'channel_id' => null
                ]),
                (new RuleEscalationRecipient())->setProperties([
                    'id' => null,
                    'contact_id' => null,
                    'contactgroup_id' => null,
                    'schedule_id' => 1,
                    'channel_id' => 1
                ])
            ]);

        $ruleEscalationMock = $this->createMock(Query::class);
        $ruleEscalationMock->expects($this->once())
            ->method('orderBy')
            ->with('position', 'asc')
            ->willReturnSelf();

        $queryResult = new ResultSet(
            new \ArrayIterator([
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'condition' => null,
                    'rule_escalation_recipient' => $firstRuleEscalationRecipientMock
                ]),
                (new RuleEscalation())->setProperties([
                    'id' => null,
                    'condition' => 'incident_severity>=crit&incident_age>5m',
                    'rule_escalation_recipient' => $secondRuleEscalationRecipientMock
                ])
            ])
        );

        $ruleEscalationMock->expects($this->once())
            ->method('execute')
            ->willReturn($queryResult);

        $ruleModel = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => 'hostgroup.name=Test%20Group',
            'timeperiod_id' => null,
            'rule_escalation' => $ruleEscalationMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->expects($this->any())
            ->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->exactly(3))
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use ($start) {
                $this->assertArrayHasKey('changed_at', $data);
                $changedAt = $data['changed_at'];
                $this->assertGreaterThan($start, $changedAt);
                unset($data['changed_at']);

                if ($table === 'rule_escalation') {
                    $this->assertEquals(
                        [
                            'rule_id' => 1337,
                            'position' => 1,
                            'condition' => 'incident_severity>=crit&incident_age>5m',
                            'name' => null,
                            'fallback_for' => null,
                            'deleted' => 'n'
                        ],
                        $data
                    );
                } elseif ($table === 'rule_escalation_recipient') {
                    if (isset($data['contactgroup_id'])) {
                        $this->assertEquals(
                            [
                                'rule_escalation_id' => 2,
                                'contact_id' => null,
                                'contactgroup_id' => 1,
                                'schedule_id' => null,
                                'channel_id' => null,
                                'deleted' => 'n'
                            ],
                            $data
                        );
                    } else {
                        $this->assertEquals(
                            [
                                'rule_escalation_id' => 2,
                                'contact_id' => null,
                                'contactgroup_id' => null,
                                'schedule_id' => 1,
                                'channel_id' => 1,
                                'deleted' => 'n'
                            ],
                            $data
                        );
                    }
                } else {
                    $this->fail(sprintf('Unexpected table %s', $table));
                }
            });

        $databaseMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('2');

        $databaseMock->expects($this->exactly(6))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertArrayHasKey('changed_at', $data);
                $changedAt = $data['changed_at'];
                $this->assertGreaterThan($start, $changedAt);
                unset($data['changed_at']);

                if ($table === 'rule') {
                    $this->assertSame(['id = ?' => 1337], $where);
                    $this->assertEquals(
                        [
                            'name' => 'Test',
                            'object_filter' => 'hostgroup.name=Test%20Group'
                        ],
                        $data
                    );
                } elseif ($table === 'rule_escalation') {
                    if (isset($data['deleted'])) {
                        // This column only exists during deletion
                        $this->assertEquals(
                            ['id IN (?)' => [2]],
                            $where
                        );
                        $this->assertEquals(
                            [
                                'deleted' => 'y',
                                'position' => null
                            ],
                            $data
                        );
                    } else {
                        $this->assertSame(['id = ?' => 1, 'rule_id = ?' => 1337], $where);
                        $this->assertEquals(
                            [
                                'position' => 0,
                                'condition' => null
                            ],
                            $data
                        );
                    }
                } elseif ($table === 'rule_escalation_recipient') {
                    if (isset($data['deleted'])) {
                        if (isset($where['id IN (?)'])) {
                            $this->assertEquals(
                                ['id IN (?)' => [2], 'deleted = ?' => 'n'],
                                $where
                            );
                        } else {
                            $this->assertEquals(
                                ['rule_escalation_id IN (?)' => [2], 'deleted = ?' => 'n'],
                                $where
                            );
                        }

                        $this->assertEquals(
                            [
                                'deleted' => 'y'
                            ],
                            $data
                        );
                    } else {
                        $this->assertSame(['id = ?' => 1], $where);
                        $this->assertEquals(
                            [
                                'id' => 1, // Actually redundant, included for consistency
                                'contact_id' => 1,
                                'contactgroup_id' => null,
                                'schedule_id' => null,
                                'channel_id' => 1
                            ],
                            $data
                        );
                    }
                } else {
                    $this->fail(sprintf('Unexpected table %s', $table));
                }
            });

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        // Not quite realistic, since load() usually fetches consistent data from the database,
        // but the test doubles used here, return different data effectively simulating an
        // edit operation. Simulating a form-submit would require a lot of knowledge about
        // its structure, unsuitable for a unit test.
        $form->load($ruleModel);

        $this->assertTrue($form->isValid(), 'Form is not valid');

        $form->storeInDatabase($databaseMock, $dbRule);
    }

    /**
     * Covers the case where only a single escalation with a single recipient and no conditions is present
     * in the form and no changes are made.
     *
     * @return void
     */
    public function testNoChangesAlsoCauseNoUpdates(): void
    {
        $providerMock = $this->createMock(ConfigProviderInterface::class);
        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([
                (new Contact())->setProperties(['id' => 1, 'full_name' => 'Test User 1'])
            ]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([
                (new Channel())->setProperties(['id' => 1, 'name' => 'Test Channel'])
            ]);

        $dbRule = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => [
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'position' => 0,
                    'condition' => null,
                    'rule_escalation_recipient' => [
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 1,
                            'contact_id' => 1,
                            'contactgroup_id' => null,
                            'schedule_id' => null,
                            'channel_id' => 1
                        ])
                    ]
                ])
            ]
        ]);

        $escalationRecipientMock = $this->createMock(Query::class);
        $escalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => 1,
                    'contact_id' => 1,
                    'contactgroup_id' => null,
                    'schedule_id' => null,
                    'channel_id' => 1
                ])
            ]);

        $ruleEscalationMock = $this->createMock(Query::class);
        $ruleEscalationMock->expects($this->once())
            ->method('orderBy')
            ->with('position', 'asc')
            ->willReturnSelf();

        $queryResult = new ResultSet(
            new \ArrayIterator([
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'condition' => null,
                    'rule_escalation_recipient' => $escalationRecipientMock
                ])
            ])
        );

        $ruleEscalationMock->expects($this->once())
            ->method('execute')
            ->willReturn($queryResult);

        $ruleModel = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => $ruleEscalationMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->expects($this->never())
            ->method('insert');
        $databaseMock->expects($this->never())
            ->method('update');
        $databaseMock->expects($this->never())
            ->method('delete');

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->load($ruleModel);

        $this->assertTrue($form->isValid(), 'Form is not valid');

        $form->storeInDatabase($databaseMock, $dbRule);
    }

    /**
     * Covers the case where only a single escalation with a single recipient and no conditions is present
     * in the form and the rule's object filter is changed.
     *
     * @return void
     */
    public function testIfARuleChangesOnlyTheRuleItselfIsUpdated(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $providerMock = $this->createMock(ConfigProviderInterface::class);
        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([
                (new Contact())->setProperties(['id' => 1, 'full_name' => 'Test User 1'])
            ]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([
                (new Channel())->setProperties(['id' => 1, 'name' => 'Test Channel'])
            ]);

        $dbRule = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => [
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'position' => 0,
                    'condition' => null,
                    'rule_escalation_recipient' => [
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 1,
                            'contact_id' => 1,
                            'contactgroup_id' => null,
                            'schedule_id' => null,
                            'channel_id' => 1
                        ])
                    ]
                ])
            ]
        ]);

        $escalationRecipientMock = $this->createMock(Query::class);
        $escalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => 1,
                    'contact_id' => 1,
                    'contactgroup_id' => null,
                    'schedule_id' => null,
                    'channel_id' => 1
                ])
            ]);

        $ruleEscalationMock = $this->createMock(Query::class);
        $ruleEscalationMock->expects($this->once())
            ->method('orderBy')
            ->with('position', 'asc')
            ->willReturnSelf();

        $queryResult = new ResultSet(
            new \ArrayIterator([
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'condition' => null,
                    'rule_escalation_recipient' => $escalationRecipientMock
                ])
            ])
        );

        $ruleEscalationMock->expects($this->once())
            ->method('execute')
            ->willReturn($queryResult);

        $ruleModel = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => 'servicegroup.name=Test%20Group',
            'timeperiod_id' => null,
            'rule_escalation' => $ruleEscalationMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('rule', $table);
                $this->assertSame(['id = ?' => 1337], $where);
                $this->assertArrayHasKey('changed_at', $data);
                $changedAt = $data['changed_at'];
                $this->assertGreaterThan($start, $changedAt);
                unset($data['changed_at']);
                $this->assertEquals(
                    [
                        'name' => 'Test',
                        'object_filter' => 'servicegroup.name=Test%20Group'
                    ],
                    $data
                );
            });
        $databaseMock->expects($this->never())
            ->method('insert');
        $databaseMock->expects($this->never())
            ->method('delete');

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->load($ruleModel);

        $this->assertTrue($form->isValid(), 'Form is not valid');

        $form->storeInDatabase($databaseMock, $dbRule);
    }

    /**
     * Covers the case where only a single escalation with a single recipient and no conditions is present
     * in the form and the escalation's conditions are changed.
     *
     * @return void
     */
    public function testIfARuleChangesOnlyTheEscalationIsUpdated(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $providerMock = $this->createMock(ConfigProviderInterface::class);
        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([
                (new Contact())->setProperties(['id' => 1, 'full_name' => 'Test User 1'])
            ]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([
                (new Channel())->setProperties(['id' => 1, 'name' => 'Test Channel'])
            ]);

        $dbRule = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => [
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'position' => 0,
                    'condition' => null,
                    'rule_escalation_recipient' => [
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 1,
                            'contact_id' => 1,
                            'contactgroup_id' => null,
                            'schedule_id' => null,
                            'channel_id' => 1
                        ])
                    ]
                ])
            ]
        ]);

        $escalationRecipientMock = $this->createMock(Query::class);
        $escalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => 1,
                    'contact_id' => 1,
                    'contactgroup_id' => null,
                    'schedule_id' => null,
                    'channel_id' => 1
                ])
            ]);

        $ruleEscalationMock = $this->createMock(Query::class);
        $ruleEscalationMock->expects($this->once())
            ->method('orderBy')
            ->with('position', 'asc')
            ->willReturnSelf();

        $queryResult = new ResultSet(
            new \ArrayIterator([
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'condition' => 'incident_severity>=crit&incident_age>5m',
                    'rule_escalation_recipient' => $escalationRecipientMock
                ])
            ])
        );

        $ruleEscalationMock->expects($this->once())
            ->method('execute')
            ->willReturn($queryResult);

        $ruleModel = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => $ruleEscalationMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->expects($this->any())
            ->method('quoteIdentifier')
            ->willReturnArgument(0);

        $databaseMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('rule_escalation', $table);
                $this->assertSame(['id = ?' => 1, 'rule_id = ?' => 1337], $where);
                $this->assertArrayHasKey('changed_at', $data);
                $changedAt = $data['changed_at'];
                $this->assertGreaterThan($start, $changedAt);
                unset($data['changed_at']);
                $this->assertEquals(
                    [
                        'position' => 0,
                        'condition' => 'incident_severity>=crit&incident_age>5m'
                    ],
                    $data
                );
            });
        $databaseMock->expects($this->never())
            ->method('insert');
        $databaseMock->expects($this->never())
            ->method('delete');

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->load($ruleModel);

        $this->assertTrue($form->isValid(), 'Form is not valid');

        $form->storeInDatabase($databaseMock, $dbRule);
    }

    /**
     * Covers the case where only a single escalation with a single recipient and no conditions is present
     * in the form and the escalation's recipient is changed.
     *
     * @return void
     */
    public function testIfARuleChangesOnlyTheEscalationRecipientIsUpdated(): void
    {
        $start = (int) (new DateTime())->format('Uv');

        $providerMock = $this->createMock(ConfigProviderInterface::class);
        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([
                (new Contact())->setProperties(['id' => 1, 'full_name' => 'Test User 1'])
            ]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([
                (new ContactGroup())->setProperties(['id' => 1, 'name' => 'Test Group'])
            ]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([
                (new Channel())->setProperties(['id' => 1, 'name' => 'Test Channel'])
            ]);

        $dbRule = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => [
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'position' => 0,
                    'condition' => null,
                    'rule_escalation_recipient' => [
                        (new RuleEscalationRecipient())->setProperties([
                            'id' => 1,
                            'contact_id' => 1,
                            'contactgroup_id' => null,
                            'schedule_id' => null,
                            'channel_id' => 1
                        ])
                    ]
                ])
            ]
        ]);

        $escalationRecipientMock = $this->createMock(Query::class);
        $escalationRecipientMock->expects($this->once())
            ->method('columns')
            ->with(['id', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id'])
            ->willReturn([
                (new RuleEscalationRecipient())->setProperties([
                    'id' => 1,
                    'contact_id' => null,
                    'contactgroup_id' => 1,
                    'schedule_id' => null,
                    'channel_id' => null
                ])
            ]);

        $ruleEscalationMock = $this->createMock(Query::class);
        $ruleEscalationMock->expects($this->once())
            ->method('orderBy')
            ->with('position', 'asc')
            ->willReturnSelf();

        $queryResult = new ResultSet(
            new \ArrayIterator([
                (new RuleEscalation())->setProperties([
                    'id' => 1,
                    'condition' => null,
                    'rule_escalation_recipient' => $escalationRecipientMock
                ])
            ])
        );

        $ruleEscalationMock->expects($this->once())
            ->method('execute')
            ->willReturn($queryResult);

        $ruleModel = (new Rule())->setProperties([
            'id' => 1337,
            'name' => 'Test',
            'object_filter' => null,
            'timeperiod_id' => null,
            'rule_escalation' => $ruleEscalationMock
        ]);

        $databaseMock = $this->createMock(Connection::class);
        $databaseMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $data, $where) use ($start) {
                $this->assertSame('rule_escalation_recipient', $table);
                $this->assertSame(['id = ?' => 1], $where);
                $this->assertArrayHasKey('changed_at', $data);
                $changedAt = $data['changed_at'];
                $this->assertGreaterThan($start, $changedAt);
                unset($data['changed_at']);
                $this->assertEquals(
                    [
                        'id' => 1,
                        'contact_id' => null,
                        'contactgroup_id' => 1,
                        'schedule_id' => null,
                        'channel_id' => null
                    ],
                    $data
                );
            });
        $databaseMock->expects($this->never())
            ->method('insert');
        $databaseMock->expects($this->never())
            ->method('delete');

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->load($ruleModel);

        $this->assertTrue($form->isValid(), 'Form is not valid');

        $form->storeInDatabase($databaseMock, $dbRule);
    }
}
