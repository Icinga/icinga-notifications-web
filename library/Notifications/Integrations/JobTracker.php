<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\JobQueue;
use Icinga\Web\Session;
use Icinga\Web\Session\SessionNamespace;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Allows to track the state of jobs queued in the `job_queue` table across requests.
 */
class JobTracker
{
    /** @var int The state used to indicate a job has not been processed after {@see static::TIMEOUT} has expired */
    public const STATE_TIMED_OUT = -1;

    /** @var int Time in seconds until a job that is not processed yet is reported as timed out */
    private const TIMEOUT = 30;

    /** @var int Time in seconds after which a job is removed from the session, even if no outcome was reported */
    private const EXPIRY = self::TIMEOUT + 60;

    /** @var string The storage key of the tracked jobs */
    private const STORAGE_KEY = 'queued-jobs';

    /** @var ?JobTracker The shared instance */
    private static ?JobTracker $instance = null;

    /** @var ?array<int, array{action: string, job_id: string, time: int, state: int}> */
    private ?array $jobs = null;

    /**
     * Get the shared instance
     *
     * @return static
     */
    public static function instance(): static
    {
        return self::$instance ??= new static(
            Database::get(),
            Session::getSession()->getNamespace('notifications.jobs')
        );
    }

    /**
     * Get whether a job in the given state is finished
     *
     * @param int $state
     *
     * @return bool
     */
    public static function isFinished(int $state): bool
    {
        return $state !== JobQueue::STATE_PENDING && $state !== JobQueue::STATE_PROCESSING;
    }

    /**
     * Create a new JobTracker
     *
     * @param Connection $db The database to look up the job's state in
     * @param SessionNamespace $session Where to remember jobs across requests
     */
    public function __construct(private Connection $db, private SessionNamespace $session)
    {
    }

    /**
     * Track the given action on the given incident in the user's session
     *
     * @param int $incidentId
     * @param 'manage'|'unmanage'|'subscribe'|'unsubscribe' $action
     * @param UuidInterface $jobId
     *
     * @return void
     */
    public function track(int $incidentId, string $action, UuidInterface $jobId): void
    {
        $entry = ['action' => $action, 'job_id' => $jobId->toString(), 'time' => time()];

        $entries = $this->entries();
        $entries[$incidentId] = $entry;
        $this->session->set(self::STORAGE_KEY, $entries);

        if ($this->jobs !== null) {
            $this->jobs[$incidentId] = array_merge($entry, ['state' => JobQueue::STATE_PENDING]);
        }
    }

    /**
     * Stop tracking the job with the given id
     *
     * @param string $jobId
     *
     * @return void
     */
    public function forget(string $jobId): void
    {
        $entries = array_filter($this->entries(), fn (array $entry) => $entry['job_id'] !== $jobId);
        $this->session->set(self::STORAGE_KEY, $entries);

        if ($this->jobs !== null) {
            $this->jobs = array_intersect_key($this->jobs, $entries);
        }
    }

    /**
     * Get the job tracked for the given incident, `null` if no job is currently queued
     *
     * @param int $incidentId
     *
     * @return ?array{action: string, job_id: string, time: int, state: int}
     */
    public function getPending(int $incidentId): ?array
    {
        $job = $this->jobs()[$incidentId] ?? null;
        if ($job === null || static::isFinished($job['state'])) {
            return null;
        }

        return $job;
    }

    /**
     * Get the state of the job with the given id, `null` if it isn't tracked
     *
     * @param string $jobId
     *
     * @return ?int
     */
    public function getState(string $jobId): ?int
    {
        foreach ($this->jobs() as $job) {
            if ($job['job_id'] === $jobId) {
                return $job['state'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{action: string, job_id: string, time: int, state: int}>
     */
    private function jobs(): array
    {
        return $this->jobs ??= $this->loadStates($this->entries());
    }

    /**
     * @return array<int, array{action: string, job_id: string, time: int}>
     */
    private function entries(): array
    {
        return array_filter(
            $this->session->get(self::STORAGE_KEY, []),
            fn (array $entry) => time() - $entry['time'] < self::EXPIRY
        );
    }

    /**
     * @param array<int, array{action: string, job_id: string, time: int}> $entries
     *
     * @return array<int, array{action: string, job_id: string, time: int, state: int}>
     */
    private function loadStates(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $states = [];
        $query = JobQueue::on($this->db)
            ->columns(['id', 'state'])
            ->filter(Filter::any(...array_map(
                fn (array $entry) => Filter::equal('id', Uuid::fromString($entry['job_id'])),
                array_values($entries)
            )));
        foreach ($query as $row) {
            $states[$row->id->toString()] = $row->state;
        }

        $jobs = [];
        foreach ($entries as $incidentId => $entry) {
            if (isset($states[$entry['job_id']])) {
                $entry['state'] = $this->stateOf($states[$entry['job_id']], $entry['time']);
                $jobs[$incidentId] = $entry;
            }
        }

        return $jobs;
    }

    private function stateOf(int $state, int $trackedAt): int
    {
        if (! static::isFinished($state) && time() - $trackedAt >= self::TIMEOUT) {
            return static::STATE_TIMED_OUT;
        }

        return $state;
    }
}
