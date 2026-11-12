<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(priority: -200)]
final readonly class JobFailureListener
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $this->connection->executeStatement("UPDATE background_job SET status = ? WHERE id = ? AND status = 'failed'", [$event->willRetry() ? 'retrying' : 'dead_letter', JobMiddleware::jobId($event->getEnvelope())]);
    }
}
