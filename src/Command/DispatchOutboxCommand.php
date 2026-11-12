<?php

namespace App\Command;

use App\Runtime\OutboxDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'statecan:outbox:dispatch', description: 'Atomically enqueue pending outbox events (safe to run concurrently).')]
final class DispatchOutboxCommand extends Command
{
    public function __construct(private readonly OutboxDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events per invocation.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->dispatcher->dispatch((int) $input->getOption('limit'));
        $output->writeln(sprintf('Queued %d outbox event(s).', $count));

        return Command::SUCCESS;
    }
}
