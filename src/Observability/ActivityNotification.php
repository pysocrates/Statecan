<?php

namespace App\Observability;

use Symfony\Component\HttpFoundation\ServerEvent;

/** Notifications carry no domain data: clients always refresh authoritative reads. */
final class ActivityNotification
{
    public function event(array $snapshot, ?string $lastEventId): ServerEvent
    {
        $revision = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        return new ServerEvent(
            json_encode(['refresh' => $revision !== $lastEventId, 'revision' => $revision], JSON_THROW_ON_ERROR),
            'refresh',
            retry: 3000,
            id: $revision,
        );
    }
    public function invalidation(array $payload): ServerEvent
    {
        // This is a transport revision, not a durable replay cursor. Reconnects
        // always reconcile authoritative components because NOTIFY is ephemeral.
        $revision = bin2hex(random_bytes(16));

        return new ServerEvent(json_encode($payload + ['revision' => $revision], JSON_THROW_ON_ERROR), 'refresh', retry: 3000, id: $revision);
    }
}
