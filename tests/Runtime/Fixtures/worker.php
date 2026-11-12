<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$events = $container->get(EventDispatcherInterface::class);
$events->addListener('task.runtime_test', static function () use ($argv): void {
    echo "JOB_STARTED\n";
    flush();
    if ('block' === ($argv[1] ?? '')) {
        sleep(60);
    }
    echo "JOB_FINISHED\n";
});
$transport = (new DoctrineTransportFactory($container->get('doctrine')))->createTransport('doctrine://default', [
    'queue_name' => $argv[2],
    'auto_setup' => false,
    'redeliver_timeout' => 300,
    'use_notify' => false,
], new PhpSerializer());
$worker = new Worker(['state_events' => $transport], $container->get(MessageBusInterface::class), $events);
$events->addListener(WorkerMessageHandledEvent::class, static fn () => $worker->stop());
$worker->run();
