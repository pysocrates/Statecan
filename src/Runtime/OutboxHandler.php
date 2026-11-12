<?php

namespace App\Runtime;

use App\Message\OutboxMessage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OutboxHandler
{
    public function __construct(private EventDispatcherInterface $events)
    {
    }

    public function __invoke(OutboxMessage $message): void
    {
        if (!$this->events->hasListeners($message->type) && !str_starts_with($message->type, 'task.')) {
            throw new \RuntimeException(sprintf('No consumer registered for outbox event %s.', $message->type));
        }
        $this->events->dispatch($message, $message->type);
    }
}
