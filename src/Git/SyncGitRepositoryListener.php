<?php

namespace App\Git;

use App\Message\OutboxMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'git.sync_requested')]
final class SyncGitRepositoryListener
{
    public function __construct(private readonly GitIndexer $indexer)
    {
    }

    public function __invoke(OutboxMessage $event): void
    {
        $this->indexer->sync($event->aggregateId);
    }
}
