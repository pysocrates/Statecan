<?php

namespace App\Observability;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

final readonly class ActivityStream
{
    public function __construct(
        private PostgresActivityListener $listener,
        private DashboardProjection $projection,
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
            // LISTEN is active before the read, so commits during the read cannot be missed.
            $event = $this->notifications->event($this->projection->snapshot($filter), $lastEventId);
            $revision = $event->getId();
            yield $event;

            while (!connection_aborted() && ($remaining = $deadline - hrtime(true)) > 0) {
                $changed = $this->listener->wait($connection, max(1, min(10000, (int) ceil($remaining / 1_000_000))));
                if ($changed) {
                    $event = $this->notifications->event($this->projection->snapshot($filter), $revision);
                    if ($event->getId() !== $revision) {
                        $revision = $event->getId();
                        yield $event;
                    }
                } else {
                    // Comments keep proxies alive without polling projections or refreshing the UI.
                    yield new ServerEvent('', comment: 'keepalive');
                }
            }
        } finally {
            $connection->close();
        }
    }
}
