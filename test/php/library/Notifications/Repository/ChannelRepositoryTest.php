<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Repository;

use DateTime;
use Icinga\Module\Notifications\Form\Data\Channel as ChannelData;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Repository\ChannelRepository;
use Icinga\Module\Notifications\Test\DbTestBackends;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\DatabaseUtils;

/**
 * Tests for {@see ChannelRepository}.
 *
 * These run against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`). Each test runs inside its own transaction which is rolled back afterwards,
 * so its writes don't leak into the next test. The channel types a channel references are seeded in
 * {@see self::initializeNotificationsDb()}, as `channel.type` is a foreign key.
 *
 * What these tests do not cover:
 * - The dereferencing of a deleted channel, as a channel can only be deleted once it isn't referenced anymore
 */
#[TransactionIsolation]
class ChannelRepositoryTest extends TestCase
{
    use DatabaseUtils;
    use DbTestBackends;

    protected static function initializeNotificationsDb(Connection $db): void
    {
        // channel.type references available_channel_type.type
        $db->insert('available_channel_type', [
            'type' => 'email', 'name' => 'Email', 'version' => '1', 'author' => 'Test', 'config_attrs' => ''
        ]);
        $db->insert('available_channel_type', [
            'type' => 'rocketchat', 'name' => 'Rocket.Chat', 'version' => '1', 'author' => 'Test',
            'config_attrs' => ''
        ]);
    }

    /**
     * Insert a channel row directly (bypassing the repository) and return its id
     *
     * @param Connection $db
     * @param string $uuid The channel's external uuid, which is unique
     * @param array<string, mixed> $overrides
     *
     * @return int
     */
    private function insertChannel(Connection $db, string $uuid, array $overrides = []): int
    {
        $db->insert('channel', array_merge([
            'external_uuid'  => static::transformUUIDForDB($db, $uuid),
            'name'           => 'Mail',
            'type'           => 'email',
            'changed_at'     => (int) (new DateTime())->format('Uv')
        ], $overrides));

        return (int) $db->lastInsertId();
    }

    /**
     * Load a channel row directly
     *
     * @param Connection $db
     * @param int $id
     *
     * @return ?Channel
     */
    private function loadChannel(Connection $db, int $id): ?Channel
    {
        return Channel::on($db)->filter(Filter::equal('channel.id', $id))->first();
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsTheChannel(Connection $db): void
    {
        $id = $this->insertChannel($db, '00000000-0000-0000-0000-000000000001', [
            'name' => 'Support', 'config' => '{"sender_mail":"noreply@example.com"}'
        ]);

        $channel = (new ChannelRepository($db))->find($id);

        $this->assertNotNull($channel, 'find() did not return the channel');
        $this->assertEquals($id, $channel->id);
        $this->assertSame('Support', $channel->name);
        $this->assertSame('email', $channel->type);
        $this->assertSame('{"sender_mail":"noreply@example.com"}', $channel->config);
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheChannelDoesNotExist(Connection $db): void
    {
        $this->assertNull((new ChannelRepository($db))->find(404));
    }

    #[DataProvider('sharedDatabases')]
    public function testFindReturnsNullIfTheChannelIsDeleted(Connection $db): void
    {
        $id = $this->insertChannel($db, '00000000-0000-0000-0000-000000000002', ['deleted' => 'y']);

        $this->assertNull((new ChannelRepository($db))->find($id), 'find() returned a deleted channel');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresTheChannel(Connection $db): void
    {
        $channelId = (new ChannelRepository($db))->create(new ChannelData(
            null,
            'Support',
            'email',
            ['sender_mail' => 'noreply@example.com']
        ));

        $stored = $this->loadChannel($db, $channelId);
        $this->assertNotNull($stored, 'The created channel was not found');
        $this->assertSame('Support', $stored->name);
        $this->assertSame('email', $stored->type);
        $this->assertSame('{"sender_mail":"noreply@example.com"}', $stored->config);
        $this->assertNotEmpty($stored->external_uuid, 'The channel should have been assigned an external uuid');
    }

    #[DataProvider('sharedDatabases')]
    public function testCreateStoresAnEmptyConfigAsObject(Connection $db): void
    {
        $channelId = (new ChannelRepository($db))->create(new ChannelData(null, 'Chat', 'rocketchat', []));

        $this->assertSame('{}', $this->loadChannel($db, $channelId)->config);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateChangesTheChannel(Connection $db): void
    {
        $id = $this->insertChannel($db, '00000000-0000-0000-0000-000000000003', [
            'name' => 'Old Name', 'config' => '{}'
        ]);

        (new ChannelRepository($db))->update(new ChannelData($id, 'Renamed', 'rocketchat', ['url' => 'localhost']));

        $stored = $this->loadChannel($db, $id);
        $this->assertSame('Renamed', $stored->name);
        $this->assertSame('rocketchat', $stored->type);
        $this->assertSame('{"url":"localhost"}', $stored->config);
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheChannelHasNoId(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChannelRepository($db))->update(new ChannelData(null, 'Support', 'email', []));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateThrowsWhenTheChannelDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChannelRepository($db))->update(new ChannelData(999, 'Support', 'email', []));
    }

    #[DataProvider('sharedDatabases')]
    public function testUpdateDoesNotTouchTheChannelIfNothingChanged(Connection $db): void
    {
        $id = $this->insertChannel($db, '00000000-0000-0000-0000-000000000004', [
            'name' => 'Support', 'config' => '{"sender_mail":"noreply@example.com"}'
        ]);
        $changedAt = $this->loadChannel($db, $id)->changed_at;

        (new ChannelRepository($db))->update(new ChannelData(
            $id,
            'Support',
            'email',
            ['sender_mail' => 'noreply@example.com']
        ));

        $this->assertEquals(
            $changedAt,
            $this->loadChannel($db, $id)->changed_at,
            'changed_at should not have been bumped as the channel did not change'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteSoftDeletesTheChannel(Connection $db): void
    {
        $id = $this->insertChannel($db, '00000000-0000-0000-0000-000000000005');

        (new ChannelRepository($db))->delete($id);

        // It's only soft-deleted: the row still exists, flagged deleted
        $stored = $this->loadRawEntity($db, $id, Channel::class);
        $this->assertNotNull($stored, 'The channel row should still exist');
        $this->assertSame('y', $stored->deleted, 'The channel should be soft-deleted, not removed');
        $this->assertNull($stored->external_uuid, 'The channel\'s external uuid should be cleared on deletion');
    }

    #[DataProvider('sharedDatabases')]
    public function testDeleteThrowsWhenTheChannelDoesNotExist(Connection $db): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChannelRepository($db))->delete(999);
    }
}
