<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;

final readonly class RuntimeMonitor
{
    public const array QUEUES = ['state_events', 'receipts', 'git_indexing', 'notifications', 'maintenance', 'failed'];

    public function __construct(private Connection $connection)
    {
    }

    public function jobs(int $limit = 50): array
    {
        return $this->connection->fetchAllAssociative('SELECT *, EXTRACT(EPOCH FROM (COALESCE(finished_at, CURRENT_TIMESTAMP) - created_at)) AS latency_seconds FROM background_job ORDER BY created_at DESC LIMIT '.max(1, min($limit, 200)));
    }

    public function queues(): array
    {
        $rows = [];
        foreach (self::QUEUES as $queue) {
            $row = $this->connection->fetchAssociative('SELECT COUNT(*) AS depth, COUNT(delivered_at) AS in_flight, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MIN(created_at))) AS oldest_age_seconds FROM messenger_messages WHERE queue_name = ?', [$queue]);
            $rows[] = ['queue' => $queue, 'depth' => (int) $row['depth'], 'in_flight' => (int) $row['in_flight'], 'oldest_age_seconds' => null === $row['oldest_age_seconds'] ? null : (float) $row['oldest_age_seconds']];
        }

        return $rows;
    }

    public function health(): array
    {
        $this->connection->executeQuery('SELECT 1');
        $failed = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM background_job WHERE status IN ('failed', 'dead_letter')");
        $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_event WHERE published_at IS NULL');
        $deadLetters = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");

        return ['status' => $deadLetters > 0 ? 'degraded' : 'ok', 'database' => 'ok', 'pending_outbox' => $pending, 'failed_jobs' => $failed, 'dead_letters' => $deadLetters, 'worker_liveness' => 'not_probed'];
    }
}
