<?php

namespace App\Command;

use App\Receipt\ReceiptProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:receipts:reconcile', description: 'Verify immutable evidence and retry pending receipts; schedule periodically.')]
final readonly class ReconcileReceiptsCommand
{
    public function __construct(private ReceiptProcessor $receipts) {}
    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->receipts->reconcile();
        $io->writeln(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['inconsistent'] ? 1 : 0;
    }
}
