<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Model\JobQueue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Tests for the quick action jobs created by {@see JobQueue::fromQuickAction()}
 *
 * The daemon decodes a job's envelope strictly by type and silently ignores unknown keys, so a malformed
 * envelope either fails or, worse, results in a job that is marked as done without having any effect.
 * That's why these tests assert the exact shape and types of the encoded envelope, not just its values.
 *
 * None of them needs a database, the job is only created, never saved.
 */
class JobQueueTest extends TestCase
{
    /** @var array<string, string> The id tags every job of these tests is created for */
    private const TAGS = ['host' => 'test-host', 'service' => 'test-service'];

    public function testFromQuickActionBuildsTheEnvelopeTheDaemonExpects(): void
    {
        $envelope = $this->envelope(JobQueue::fromQuickAction('subscribe', 42, self::TAGS));

        $this->assertSame(['version', 'format', 'time', 'payload'], array_keys($envelope));
        $this->assertSame(JobQueue::ENVELOPE_VERSION, $envelope['version']);
        $this->assertSame('quick_action', $envelope['format']);
        $this->assertSame(
            ['action' => 'subscribe', 'contact_id' => 42, 'object_tags' => self::TAGS],
            $envelope['payload']
        );
    }

    public function testFromQuickActionEncodesTheObjectTagsAsAnObject(): void
    {
        $job = JobQueue::fromQuickAction('subscribe', 42, ['host' => 'test-host']);

        $this->assertStringContainsString(
            '"object_tags":{"host":"test-host"}',
            $job->envelope,
            'The object tags are not encoded as a JSON object, which the daemon fails to decode'
        );
    }

    public function testFromQuickActionStampsTheTimeInMilliseconds(): void
    {
        $before = (int) (new DateTime())->format('Uv');
        $job = JobQueue::fromQuickAction('subscribe', 42, self::TAGS);
        $after = (int) (new DateTime())->format('Uv');

        $time = $this->envelope($job)['time'];

        $this->assertIsInt($time);
        $this->assertGreaterThanOrEqual($before, $time, 'The envelope is not stamped in milliseconds');
        $this->assertLessThanOrEqual($after, $time, 'The envelope is not stamped in milliseconds');

        $this->assertInstanceOf(DateTime::class, $job->last_update);
        $this->assertSame(
            $time,
            (int) $job->last_update->format('Uv'),
            'The job was last updated at a different time than its envelope was stamped'
        );
    }

    public function testFromQuickActionCreatesANewPendingJob(): void
    {
        $job = JobQueue::fromQuickAction('subscribe', 42, self::TAGS);

        $this->assertTrue($job->isNew(), 'The job is not new');
        $this->assertSame(JobQueue::STATE_PENDING, $job->state);

        $this->assertInstanceOf(UuidInterface::class, $job->id);

        /** @var FieldsInterface $fields */
        $fields = $job->id->getFields();
        $this->assertSame(4, $fields->getVersion(), 'The job id is not a random UUID');
    }

    public function testFromQuickActionCreatesAJobWithAUniqueId(): void
    {
        $this->assertNotEquals(
            JobQueue::fromQuickAction('subscribe', 42, self::TAGS)->id->toString(),
            JobQueue::fromQuickAction('subscribe', 42, self::TAGS)->id->toString()
        );
    }

    public function testFromQuickActionRejectsEmptyObjectTags(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobQueue::fromQuickAction('subscribe', 42, []);
    }

    /**
     * Decode the given job's envelope
     *
     * @return array<string, mixed>
     */
    private function envelope(JobQueue $job): array
    {
        return json_decode($job->envelope, true, flags: JSON_THROW_ON_ERROR);
    }
}
