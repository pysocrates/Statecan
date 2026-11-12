<?php

namespace App\Observability;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

final readonly class ActivityStream
{
    public function __construct(
        private PostgresActivityListener $listener,
        private ComponentProjection $projection,
        private ActivityInvalidation $invalidations,
        private ActivityNotification $notifications,
        #[Autowire(env: 'int:STATECAN_SSE_DURATION')] private int $duration,
    ) {
    }

    /** @return \Generator<ServerEvent> */
    public function events(ObservationFilter $filter, ?string $lastEventId): \Generator
    {
        $connection = $this->listener->subscribe();
        $deadline = hrtime(true) + max(1, min(120, $this->duration)) * 1_000_000_000;

        try {
            // LISTEN precedes context validation; reconnects reconcile without
            // rebuilding contracts, histories and Git graphs on the stream.
            $project = $this->projection->context($filter);
            yield $this->notifications->invalidation($this->invalidations->batch([], $project, true));

            while (!connection_aborted() && ($remaining = $deadline - hrtime(true)) > 0) {
                $batch = $this->listener->changes($connection, max(1, min(10000, (int) ceil($remaining / 1_000_000))));
                if (null !== $batch) {
                    $payload = $this->invalidations->batch($batch['changes'], $project, $batch['overflow']);
                    if ($payload['refresh']) {
                        yield $this->notifications->invalidation($payload);
                    }
                } else {
                    yield new ServerEvent('', comment: 'keepalive');
                }
            }
        } finally {
            $connection->close();
        }
    }
}
