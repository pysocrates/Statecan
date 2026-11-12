<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$kernel->getContainer()->get('event_dispatcher')->addListener('task.runtime_test', static function () use ($argv): void {
    echo "JOB_STARTED\n";
    flush();
    if ('block' === ($argv[1] ?? '')) {
        sleep(60);
    }
    echo "JOB_FINISHED\n";
});
$application = new Application($kernel);
$application->setAutoExit(false);
exit($application->run(new ArrayInput([
    'command' => 'messenger:consume',
    'receivers' => ['state_events'],
    '--limit' => 1,
    '--time-limit' => 10,
    '--no-interaction' => true,
])));
