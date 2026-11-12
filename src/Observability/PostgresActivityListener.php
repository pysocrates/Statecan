<?php

namespace App\Observability;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class PostgresActivityListener
{
    public function __construct(private Connection $connection)
    {
    }

    public function subscribe(): Connection
    {
        $params = $this->connection->getParams();
        $params['persistent'] = false;
        $params['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
        $listener = DriverManager::getConnection($params);

        try {
            $listener->executeStatement('LISTEN statecan_dashboard');

            return $listener;
        } catch (\Throwable $error) {
            $listener->close();
            throw $error;
        }
    }

    public function wait(Connection $listener, int $timeoutMilliseconds): bool
    {
        return null !== $this->changes($listener, $timeoutMilliseconds);
    }

    /** null means timeout; batches cap memory and reconcile if notifications overflow. */
    public function changes(Connection $listener, int $timeoutMilliseconds): ?array
    {
        $pdo = $listener->getNativeConnection();
        if (!$pdo instanceof \Pdo\Pgsql) {
            throw new \LogicException('Dashboard notifications require the pdo_pgsql driver.');
        }
        $notification = $pdo->getNotify(\PDO::FETCH_ASSOC, $timeoutMilliseconds);
        if (false === $notification) {
            return null;
        }
        $changes = [];
        $overflow = false;
        $count = 0;
        do {
            if (++$count <= 128) {
                try {
                    $change = json_decode($notification['payload'], true, 16, JSON_THROW_ON_ERROR);
                    $changes[] = is_array($change) ? $change : [];
                } catch (\JsonException) {
                    $changes[] = [];
                }
            } else {
                $overflow = true;
            }
            // Bound draining even under continuous writes; remaining notifications
            // will be coalesced by the next iteration after reconciliation.
            if ($count >= 512) {
                $overflow = true;
                break;
            }
        } while (false !== ($notification = $pdo->getNotify(\PDO::FETCH_ASSOC, 0)));

        return ['changes' => $changes, 'overflow' => $overflow];
    }
}
