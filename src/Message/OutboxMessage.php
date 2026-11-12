<?php

namespace App\Message;

use App\Runtime\JobMessage;

/** Only stable identifiers and version references belong in payload. */
final readonly class OutboxMessage implements JobMessage
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $aggregateId,
        public array $payload,
        public string $correlationId,
    ) {
    }

    public function getJobId(): string
    {
        return $this->eventId;
    }
}
