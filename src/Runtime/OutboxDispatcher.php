<?php

namespace App\Runtime;

use App\Message\OutboxMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class OutboxDispatcher
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(int $limit = 100): int
    {
        $count = 0;
        for ($i = 0; $i < max(1, min($limit, 1000)); ++$i) {
            $event = $this->connection->transactional(function (Connection $db): ?array {
                $row = $db->fetchAssociative('SELECT id, type, aggregate_id, payload FROM outbox_event WHERE published_at IS NULL ORDER BY created_at, id LIMIT 1 FOR UPDATE SKIP LOCKED');
                if (false === $row) {
                    return null;
                }
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $correlationId = (string) ($payload['correlationId'] ?? $row['id']);
                $message = new OutboxMessage($row['id'], $row['type'], $row['aggregate_id'], $payload, $correlationId);
                // Doctrine transports MUST share this connection; enqueue and publish commit together.
                $this->bus->dispatch($message, [new TransportNamesStamp([self::queueFor($row['type'])])]);
                $db->executeStatement('UPDATE outbox_event SET published_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);

                return ['event_id' => $row['id'], 'correlation_id' => $correlationId];
            });
            if (null === $event) {
                break;
            }
            ++$count;
            $this->logger->info('Outbox event queued', $event);
        }

        return $count;
    }

    public static function queueFor(string $type): string
    {
        return match (strtok($type, '.')) {
            'receipt' => 'receipts',
            'git' => 'git_indexing',
            'notification' => 'notifications',
            'maintenance', 'lease' => 'maintenance',
            default => 'state_events',
        };
    }
}
