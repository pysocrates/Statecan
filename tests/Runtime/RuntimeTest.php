<?php

namespace App\Tests\Runtime;

use App\Entity\OutboxEvent;
use App\Message\OutboxMessage;
use App\Runtime\OutboxDispatcher;
use App\Runtime\RuntimeMonitor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Process\Process;

final class RuntimeTest extends KernelTestCase
{
    public function testDurableDispatchAndWorkerRedelivery(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $container->get(Connection::class);
        $event = new OutboxEvent('task.runtime_test', 'runtime-test', ['version' => 1]);
        $em->persist($event);
        $em->flush();
        $dispatcher = $container->get(OutboxDispatcher::class);
        $monitor = $container->get(RuntimeMonitor::class);
        $calls = 0;
        $container->get(EventDispatcherInterface::class)->addListener('task.runtime_test', static function (OutboxMessage $message) use (&$calls, $event): void {
            self::assertSame($event->getId(), $message->eventId);
            ++$calls;
        });
        $transport = $container->get('messenger.transport.state_events');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $bus = $container->get(MessageBusInterface::class);
        try {
            self::assertSame(1, $dispatcher->dispatch());
            self::assertSame(0, $dispatcher->dispatch());
            self::assertNotNull($db->fetchOne('SELECT published_at FROM outbox_event WHERE id = ?', [$event->getId()]));
            self::assertSame(1, $monitor->queues()[0]['depth']);
            $phpArgs = json_decode(getenv('STATECAN_TEST_PHP_ARGS') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
            $command = [PHP_BINARY, ...$phpArgs, __DIR__.'/Fixtures/worker.php'];
            $worker = new Process([...$command, 'block'], self::$kernel->getProjectDir(), ['APP_ENV' => 'test']);
            $worker->start();
            self::assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'JOB_STARTED')), $worker->getErrorOutput());
            $worker->signal(9);
            $worker->wait();
            self::assertSame('processing', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$event->getId()]));
            // Advance the expired delivery timestamp instead of waiting five minutes.
            $db->executeStatement("UPDATE messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '301 seconds' WHERE queue_name = 'state_events'");
            $restarted = new Process([...$command, 'finish'], self::$kernel->getProjectDir(), ['APP_ENV' => 'test']);
            $restarted->mustRun();
            self::assertStringContainsString('JOB_FINISHED', $restarted->getOutput());
            // A duplicate delivery after metadata committed must suppress the handler.
            $bus->dispatch((new \Symfony\Component\Messenger\Envelope(new OutboxMessage($event->getId(), 'task.runtime_test', 'runtime-test', ['version' => 1], $event->getId())))->with(new ReceivedStamp('state_events')));
            self::assertSame(0, $calls);
            self::assertSame('completed', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$event->getId()]));
            self::assertSame(2, (int) $db->fetchOne('SELECT attempts FROM background_job WHERE id = ?', [$event->getId()]));
            self::assertSame(0, $monitor->queues()[0]['depth']);
            self::assertSame('ok', $monitor->health()['status']);
        } finally {
            if (isset($worker) && $worker->isRunning()) {
                $worker->stop(0);
            }
            $db->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', ['%'.$event->getId().'%']);
            $db->executeStatement('DELETE FROM background_job WHERE id = ?', [$event->getId()]);
            $db->executeStatement('DELETE FROM outbox_event WHERE id = ?', [$event->getId()]);
        }
    }
}
