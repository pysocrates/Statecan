<?php

namespace App\Tests\Runtime;

use App\Runtime\RuntimeMonitor;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class WorkerHealthTest extends KernelTestCase
{
    public function testIdleWorkerCoverageAndStalledRuntime(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $monitor = $container->get(RuntimeMonitor::class);
        $events = $container->get(EventDispatcherInterface::class);
        $db = $container->get(Connection::class);
        $receivers = [];
        foreach (array_diff(RuntimeMonitor::QUEUES, ['failed']) as $queue) {
            $receivers[$queue] = new InMemoryTransport();
        }
        $worker = new Worker($receivers, $container->get(MessageBusInterface::class), $events);
        $workerId = null;
        $jobId = 'health-test-'.bin2hex(random_bytes(8));
        $listener = function (WorkerRunningEvent $event) use ($monitor, $db, $jobId, &$workerId): void {
            $health = $monitor->health();
            self::assertSame('ok', $health['worker_liveness']);
            self::assertSame([], $health['missing_worker_queues']);
            $workerId = $db->fetchOne('SELECT id FROM worker_process WHERE pid = ? AND stopped_at IS NULL ORDER BY started_at DESC LIMIT 1', [getmypid()]);
            $db->executeStatement("INSERT INTO background_job (id, message_type, status, attempts, correlation_id, created_at, started_at, worker_id, queue_name) VALUES (?, 'fixture', 'processing', 1, ?, CURRENT_TIMESTAMP - INTERVAL '301 seconds', CURRENT_TIMESTAMP - INTERVAL '301 seconds', ?, 'state_events')", [$jobId, $jobId, $workerId]);
            $jobs = array_column($monitor->jobs(200), null, 'id');
            self::assertSame('runtime_exceeded', $jobs[$jobId]['stall_reason']);
            self::assertSame('degraded', $monitor->health()['status']);
            // Delay a ready fixture beyond the queue-latency threshold.
            $db->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (?, '{}', 'maintenance', (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '301 seconds', (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '301 seconds')", [$jobId]);
            self::assertContains('maintenance', $monitor->health()['stalled_queues']);
            $db->executeStatement("INSERT INTO outbox_event (id, type, aggregate_id, payload, created_at) VALUES (?, 'task.health_test', 'fixture', '{}', (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '301 seconds')", [$jobId]);
            self::assertTrue($monitor->health()['outbox_stalled']);
            $event->getWorker()->stop();
        };
        $events->addListener(WorkerRunningEvent::class, $listener);
        try {
            $worker->run(['sleep' => 1000]);
            $workers = array_column($monitor->workers(), null, 'id');
            self::assertSame('stopped', $workers[$workerId]['liveness']);
            self::assertSame('degraded', $monitor->health()['worker_liveness']);
        } finally {
            $events->removeListener(WorkerRunningEvent::class, $listener);
            $db->delete('messenger_messages', ['body' => $jobId]);
            $db->delete('background_job', ['id' => $jobId]);
            $db->delete('outbox_event', ['id' => $jobId]);
            if ($workerId) {
                $db->delete('worker_process', ['id' => $workerId]);
            }
        }
    }
}
