<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Event\ConsoleAlarmEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Uid\Uuid;

final class WorkerHeartbeat
{
    private ?string $id = null;
    private ?Connection $heartbeat = null;
    private int $lastWrite = 0;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'int:STATECAN_WORKER_TTL')] private readonly int $ttl,
    )
    {
    }

    #[AsEventListener]
    public function started(WorkerStartedEvent $event): void
    {
        // Independent autocommit connection: signal heartbeats cannot join handler transactions.
        $params = $this->connection->getParams();
        $params['persistent'] = false;
        $params['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
        $this->heartbeat = DriverManager::getConnection($params);
        $this->heartbeat->executeStatement("SET statement_timeout = '2s'");
        $this->id = Uuid::v7()->toRfc4122();
        $metadata = $event->getWorker()->getMetadata();
        $transports = $metadata->getTransportNames();
        if (null !== $metadata->getQueueNames()) {
            $transports = array_values(array_intersect($transports, $metadata->getQueueNames()));
        }
        $this->heartbeat->executeStatement('INSERT INTO worker_process (id, hostname, pid, transports, started_at, last_seen_at, last_progress_at, handled_count) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0)', [$this->id, gethostname() ?: 'unknown', getmypid(), json_encode($transports, JSON_THROW_ON_ERROR)]);
        $this->lastWrite = hrtime(true);
    }

    #[AsEventListener]
    public function running(WorkerRunningEvent $event): void
    {
        $this->pulse();
    }

    #[AsEventListener]
    public function alarm(ConsoleAlarmEvent $event): void
    {
        $this->pulse();
    }

    private function pulse(): void
    {
        if (null !== $this->id && hrtime(true) - $this->lastWrite >= max(1, min(15, intdiv($this->ttl, 3))) * 1_000_000_000) {
            $this->heartbeat->executeStatement('UPDATE worker_process SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [$this->id]);
            $this->lastWrite = hrtime(true);
        }
    }

    #[AsEventListener(priority: 100)]
    public function received(WorkerMessageReceivedEvent $event): void
    {
        if (null !== $this->id) {
            $this->heartbeat->executeStatement('UPDATE worker_process SET current_job_id = ?, last_progress_at = CURRENT_TIMESTAMP, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [JobMiddleware::jobId($event->getEnvelope()), $this->id]);
        }
    }

    #[AsEventListener(priority: -300)]
    public function finished(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        if (null !== $this->id) {
            $this->heartbeat->executeStatement('UPDATE worker_process SET current_job_id = NULL, last_progress_at = CURRENT_TIMESTAMP, last_seen_at = CURRENT_TIMESTAMP, handled_count = handled_count + 1 WHERE id = ?', [$this->id]);
        }
    }

    #[AsEventListener]
    public function stopped(WorkerStoppedEvent $event): void
    {
        if (null !== $this->id) {
            $this->heartbeat->executeStatement('UPDATE worker_process SET stopped_at = CURRENT_TIMESTAMP, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [$this->id]);
            $this->id = null;
            $this->heartbeat->close();
            $this->heartbeat = null;
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }
}
