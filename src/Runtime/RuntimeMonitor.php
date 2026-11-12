<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RuntimeMonitor
{
    public const array QUEUES = ['state_events', 'receipts', 'git_indexing', 'notifications', 'maintenance', 'failed'];

    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'int:STATECAN_WORKER_TTL')] private int $workerTtl,
        #[Autowire(env: 'int:STATECAN_JOB_TIMEOUT')] private int $jobTimeout,
        #[Autowire(env: 'int:STATECAN_QUEUE_STALL')] private int $queueStall,
    ) {
    }

    private function jobProjection(): string
    {
        $ttl = max(1, $this->workerTtl);
        $timeout = max(1, $this->jobTimeout);

        return "SELECT j.*, EXTRACT(EPOCH FROM (COALESCE(j.finished_at, CURRENT_TIMESTAMP) - j.created_at)) AS latency_seconds,
            CASE WHEN j.status <> 'processing' THEN NULL
                WHEN j.started_at < CURRENT_TIMESTAMP - INTERVAL '$timeout seconds' THEN 'runtime_exceeded'
                WHEN j.worker_id IS NOT NULL AND w.id IS NULL THEN 'worker_missing'
                WHEN w.stopped_at IS NOT NULL THEN 'worker_stopped'
                WHEN w.last_seen_at < CURRENT_TIMESTAMP - INTERVAL '$ttl seconds' THEN 'heartbeat_expired'
                WHEN j.worker_id IS NULL AND j.started_at < CURRENT_TIMESTAMP - INTERVAL '$ttl seconds' THEN 'worker_untracked'
                ELSE NULL END AS stall_reason
            FROM background_job j LEFT JOIN worker_process w ON w.id = j.worker_id";
    }

    public function jobs(int $limit = 50): array
    {
        return $this->connection->fetchAllAssociative($this->jobProjection().' ORDER BY j.created_at DESC LIMIT '.max(1, min($limit, 200)));
    }

    public function workers(int $limit = 100): array
    {
        $ttl = max(1, $this->workerTtl);
        $rows = $this->connection->fetchAllAssociative("SELECT *, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - last_seen_at)) AS heartbeat_age_seconds,
            CASE WHEN stopped_at IS NOT NULL THEN 'stopped'
                WHEN last_seen_at < CURRENT_TIMESTAMP - INTERVAL '$ttl seconds' THEN 'stale'
                WHEN current_job_id IS NOT NULL THEN 'busy' ELSE 'idle' END AS liveness
            FROM worker_process ORDER BY last_seen_at DESC LIMIT ".max(1, min($limit, 200)));
        foreach ($rows as &$row) {
            $row['transports'] = json_decode($row['transports'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public function queues(): array
    {
        $ttl = max(1, $this->workerTtl);
        $rows = [];
        foreach (self::QUEUES as $queue) {
            $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS depth, COUNT(delivered_at) AS in_flight,
                EXTRACT(EPOCH FROM ((CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - MIN(created_at))) AS oldest_age_seconds,
                COUNT(*) FILTER (WHERE delivered_at IS NULL AND available_at <= CURRENT_TIMESTAMP AT TIME ZONE 'UTC') AS ready,
                EXTRACT(EPOCH FROM ((CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - MIN(available_at) FILTER (WHERE delivered_at IS NULL AND available_at <= CURRENT_TIMESTAMP AT TIME ZONE 'UTC'))) AS oldest_ready_seconds
                FROM messenger_messages WHERE queue_name = ?", [$queue]);
            $live = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM worker_process WHERE stopped_at IS NULL AND last_seen_at >= CURRENT_TIMESTAMP - INTERVAL '$ttl seconds' AND jsonb_exists(transports, ?)", [$queue]);
            $rows[] = ['queue' => $queue, 'depth' => (int) $row['depth'], 'in_flight' => (int) $row['in_flight'],
                'oldest_age_seconds' => null === $row['oldest_age_seconds'] ? null : (float) $row['oldest_age_seconds'],
                'ready' => (int) $row['ready'], 'oldest_ready_seconds' => null === $row['oldest_ready_seconds'] ? null : (float) $row['oldest_ready_seconds'],
                'live_workers' => $live, 'backlog_stalled' => 'failed' !== $queue && null !== $row['oldest_ready_seconds'] && (float) $row['oldest_ready_seconds'] > max(1, $this->queueStall)];
        }

        return $rows;
    }

    public function health(): array
    {
        $this->connection->executeQuery('SELECT 1');
        $failed = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM background_job WHERE status IN ('failed', 'dead_letter')");
        $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_event WHERE published_at IS NULL');
        $oldestPending = $this->connection->fetchOne("SELECT EXTRACT(EPOCH FROM ((CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - MIN(created_at))) FROM outbox_event WHERE published_at IS NULL");
        $outboxStalled = null !== $oldestPending && false !== $oldestPending && (float) $oldestPending > max(1, $this->queueStall);
        $deadLetters = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $stalled = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ('.$this->jobProjection()." WHERE j.status = 'processing') jobs WHERE stall_reason IS NOT NULL");
        $missing = [];
        $backlogs = [];
        foreach ($this->queues() as $queue) {
            if ('failed' !== $queue['queue'] && 0 === $queue['live_workers']) {
                $missing[] = $queue['queue'];
            }
            if ($queue['backlog_stalled']) {
                $backlogs[] = $queue['queue'];
            }
        }

        return ['status' => $deadLetters > 0 || $stalled > 0 || $missing || $backlogs || $outboxStalled ? 'degraded' : 'ok',
            'database' => 'ok', 'pending_outbox' => $pending, 'failed_jobs' => $failed, 'dead_letters' => $deadLetters,
            'worker_liveness' => $missing ? 'degraded' : 'ok', 'missing_worker_queues' => $missing,
            'stalled_jobs' => $stalled, 'stalled_queues' => $backlogs,
            'oldest_pending_outbox_seconds' => null === $oldestPending ? null : (float) $oldestPending,
            'outbox_stalled' => $outboxStalled,
            'worker_ttl_seconds' => max(1, $this->workerTtl), 'job_timeout_seconds' => max(1, $this->jobTimeout)];
    }
}
