<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Integrations\JobTracker;
use Icinga\Module\Notifications\Model\JobQueue;
use Icinga\Module\Notifications\Test\DbTestBackends;
use Icinga\Web\Session\SessionNamespace;
use ipl\Sql\Connection;
use ipl\Sql\Test\SharedDatabases\TransactionIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Contract of {@see JobTracker}: a job tracked for an incident is pending until the daemon has processed it, and
 * stays tracked across requests sharing the same session until it is forgotten. Only one job is tracked per
 * incident, and a job whose `job_queue` row does not exist is not tracked at all.
 *
 * A request is simulated by a new instance on the same session, as the instances load the states only once.
 * The timeout and expiry are not covered here, as they depend on the current time.
 *
 * Every test runs against real databases — once for MySQL and once for PostgreSQL (see {@see DbTestBackends} /
 * `#[DataProvider('sharedDatabases')]`), each within its own transaction which is rolled back afterwards.
 */
#[TransactionIsolation]
class JobTrackerTest extends TestCase
{
    use DbTestBackends;

    /** @var Connection The database of the current test, set by every test */
    private Connection $db;

    /**
     * Nothing to seed, as every test seeds the `job_queue` rows it requires itself
     */
    protected static function initializeNotificationsDb(Connection $db): void
    {
    }

    #[DataProvider('sharedDatabases')]
    public function testATrackedJobIsPending(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $jobId = $this->seedJob();

        $tracker = new JobTracker($db, $session);
        $this->assertNull($tracker->getPending(1));

        $tracker->track(1, 'subscribe', $jobId);

        $expected = ['action' => 'subscribe', 'job_id' => $jobId->toString(), 'state' => JobQueue::STATE_PENDING];
        $this->assertSame(
            $expected,
            $this->pendingWithoutTime($tracker, 1),
            'The job is not pending for the instance that tracked it'
        );
        $this->assertSame(
            $expected,
            $this->pendingWithoutTime(new JobTracker($db, $session), 1),
            'The job is not pending in a later request'
        );
    }

    #[DataProvider('sharedDatabases')]
    public function testAProcessingJobIsStillPending(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $jobId = $this->seedJob(JobQueue::STATE_PROCESSING);
        (new JobTracker($db, $session))->track(1, 'subscribe', $jobId);

        $tracker = new JobTracker($db, $session);
        $this->assertSame('subscribe', $tracker->getPending(1)['action'] ?? null);
        $this->assertSame(JobQueue::STATE_PROCESSING, $tracker->getState($jobId->toString()));
    }

    #[DataProvider('sharedDatabases')]
    public function testNothingIsPendingForAnUntrackedIncident(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $jobId = $this->seedJob();
        (new JobTracker($db, $session))->track(1, 'subscribe', $jobId);

        $tracker = new JobTracker($db, $session);
        $this->assertNull($tracker->getPending(2));
        $this->assertNull($tracker->getState(Uuid::uuid4()->toString()));
    }

    #[DataProvider('sharedDatabases')]
    public function testTrackingReplacesThePreviousJobOfTheIncident(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $firstJobId = $this->seedJob();
        $secondJobId = $this->seedJob();

        $tracker = new JobTracker($db, $session);
        $tracker->track(1, 'subscribe', $firstJobId);
        $tracker->track(1, 'unsubscribe', $secondJobId);

        $tracker = new JobTracker($db, $session);
        $this->assertSame('unsubscribe', $tracker->getPending(1)['action'] ?? null);
        $this->assertNull($tracker->getState($firstJobId->toString()), 'The replaced job is still tracked');
    }

    #[DataProvider('sharedDatabases')]
    public function testAFinishedJobIsNoLongerPending(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $doneJobId = $this->seedJob(JobQueue::STATE_DONE);
        $failedJobId = $this->seedJob(JobQueue::STATE_ERROR);

        $tracker = new JobTracker($db, $session);
        $tracker->track(1, 'subscribe', $doneJobId);
        $tracker->track(2, 'subscribe', $failedJobId);

        $tracker = new JobTracker($db, $session);
        $this->assertNull($tracker->getPending(1));
        $this->assertNull($tracker->getPending(2));
        $this->assertSame(JobQueue::STATE_DONE, $tracker->getState($doneJobId->toString()));
        $this->assertSame(JobQueue::STATE_ERROR, $tracker->getState($failedJobId->toString()));
    }

    #[DataProvider('sharedDatabases')]
    public function testForgetStopsTrackingOnlyThatJob(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $forgottenJobId = $this->seedJob(JobQueue::STATE_DONE)->toString();
        $otherJobId = $this->seedJob()->toString();

        $tracker = new JobTracker($db, $session);
        $tracker->track(1, 'subscribe', Uuid::fromString($forgottenJobId));
        $tracker->track(2, 'subscribe', Uuid::fromString($otherJobId));

        $tracker = new JobTracker($db, $session);
        $this->assertSame(JobQueue::STATE_DONE, $tracker->getState($forgottenJobId));

        $tracker->forget($forgottenJobId);

        $this->assertNull($tracker->getState($forgottenJobId), 'The job is still tracked by the instance');
        $this->assertSame(JobQueue::STATE_PENDING, $tracker->getState($otherJobId));

        $tracker = new JobTracker($db, $session);
        $this->assertNull($tracker->getState($forgottenJobId), 'The job is still tracked in a later request');
        $this->assertSame(JobQueue::STATE_PENDING, $tracker->getState($otherJobId));
    }

    #[DataProvider('sharedDatabases')]
    public function testAJobWithoutARowIsNotTracked(Connection $db): void
    {
        $this->db = $db;

        $session = new SessionNamespace();
        $jobId = Uuid::uuid4();
        (new JobTracker($db, $session))->track(1, 'subscribe', $jobId);

        $tracker = new JobTracker($db, $session);
        $this->assertNull($tracker->getPending(1));
        $this->assertNull($tracker->getState($jobId->toString()));
    }

    /**
     * Not driven by a data provider, as every test of this class must use the same one, see {@see DbTestBackends}
     */
    public function testIsFinished(): void
    {
        $this->assertFalse(JobTracker::isFinished(JobQueue::STATE_PENDING));
        $this->assertFalse(JobTracker::isFinished(JobQueue::STATE_PROCESSING));
        $this->assertTrue(JobTracker::isFinished(JobQueue::STATE_DONE));
        $this->assertTrue(JobTracker::isFinished(JobQueue::STATE_ERROR));
        $this->assertTrue(JobTracker::isFinished(JobTracker::STATE_TIMED_OUT));
    }

    /**
     * Get the job pending for the given incident without its tracking time, which is not predictable
     *
     * @param JobTracker $tracker
     * @param int $incidentId
     *
     * @return ?array{action: string, job_id: string, state: int}
     */
    private function pendingWithoutTime(JobTracker $tracker, int $incidentId): ?array
    {
        $job = $tracker->getPending($incidentId);
        if ($job !== null) {
            unset($job['time']);
        }

        return $job;
    }

    /**
     * Insert a job in the given state and return its id
     *
     * @param int $state
     *
     * @return UuidInterface
     */
    private function seedJob(int $state = JobQueue::STATE_PENDING): UuidInterface
    {
        $id = Uuid::uuid4();
        $this->db->insert('job_queue', [
            'id'          => static::transformUUIDForDB($this->db, $id->toString()),
            'last_update' => (int) (microtime(true) * 1000),
            'state'       => $state,
            'envelope'    => '{}'
        ]);

        return $id;
    }
}
