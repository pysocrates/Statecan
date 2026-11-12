<?php

namespace App\Command;

use App\Agent\LeaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:leases:recover', description: 'Recover an expired lease batch; schedule periodically.')]
final readonly class RecoverLeasesCommand
{
    public function __construct(private LeaseService $leases) {}
    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln('Recovered: '.$this->leases->recoverExpired());

        return 0;
    }
}
