<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Model;
use InvalidArgumentException;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behavior\UUID as UUIDBehavior;
use ipl\Orm\Behaviors;
use JsonException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * A job for the daemon to process
 *
 * @property UuidInterface $id
 * @property DateTime $last_update
 * @property int $state
 * @property string $envelope
 */
class JobQueue extends Model
{
    /** @var int The job waits to be claimed by the daemon */
    public const STATE_PENDING = 0;

    /** @var int The job has been claimed and is being processed by the daemon */
    public const STATE_PROCESSING = 1;

    /** @var int The daemon finished processing the job, this does not imply the job had any effect */
    public const STATE_DONE = 2;

    /** @var int The daemon failed to process the job */
    public const STATE_ERROR = 64;

    /** @var int The version used for the envelope */
    public const ENVELOPE_VERSION = 1;

    /**
     * Create a new, pending job that represents the given quick action
     *
     * The daemon decodes the envelope strictly by type, so `contact_id` must be an integer and `object_tags` a
     * non-empty object. It also ignores unknown keys silently, a misspelled key results in a job that is marked as
     * done without having any effect.
     *
     * @param 'manage'|'unmanage'|'subscribe'|'unsubscribe' $action The type of action to perform
     * @param int $contactId The id of the contact whose role is changed
     * @param array<string, string> $objectTags The object's full id tags
     * @param ?UuidInterface $uuid The job's id, a new one is generated if not given
     *
     * @return static
     *
     * @throws InvalidArgumentException If $objectTags is empty
     * @throws JsonException If the envelope cannot be encoded
     */
    public static function fromQuickAction(
        string $action,
        int $contactId,
        array $objectTags,
        ?UuidInterface $uuid = null
    ): static {
        if (empty($objectTags)) {
            throw new InvalidArgumentException('Object tags must not be empty');
        }

        $now = new DateTime();
        $job = (new static())->setNew();
        $job->id = $uuid ?? Uuid::uuid4();
        $job->last_update = $now;
        $job->state = static::STATE_PENDING;
        $job->envelope = json_encode([
            'version' => static::ENVELOPE_VERSION,
            'format' => 'quick_action',
            'time' => (int) $now->format('Uv'),
            'payload' => [
                'action' => $action,
                'contact_id' => $contactId,
                'object_tags' => $objectTags
            ]
        ], JSON_THROW_ON_ERROR);

        return $job;
    }

    public function getTableName(): string
    {
        return 'job_queue';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'last_update',
            'state',
            'envelope'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new UUIDBehavior(['id']));
        $behaviors->add(new MillisecondTimestamp(['last_update']));
    }
}
