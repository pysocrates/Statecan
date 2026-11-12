<?php

namespace App\Observability;

use App\Runtime\RuntimeMonitor;
use Doctrine\DBAL\Connection;

/** Focused application-wide read; queue bodies and arbitrary payloads stay private. */
final readonly class RuntimeConsoleProjection
{
    public function __construct(private RuntimeMonitor $monitor, private Connection $db)
    {
    }

    public function read(): array
    {
        return [
            'health' => $this->monitor->health(),
            'queues' => $this->monitor->queues(),
            'workers' => $this->monitor->workers(50),
            'jobs' => $this->monitor->jobs(50),
            'outbox' => $this->db->fetchAllAssociative('SELECT id, type, aggregate_id, created_at, published_at FROM outbox_event ORDER BY created_at DESC, id DESC LIMIT 25'),
            'observed_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }
}
