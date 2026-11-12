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
        $pdo = $listener->getNativeConnection();
        if (!$pdo instanceof \Pdo\Pgsql) {
            throw new \LogicException('Dashboard notifications require the pdo_pgsql driver.');
        }

        if (false === $pdo->getNotify(\PDO::FETCH_ASSOC, $timeoutMilliseconds)) {
            return false;
        }

        // One authoritative refresh covers all changes already queued on this connection.
        while (false !== $pdo->getNotify(\PDO::FETCH_ASSOC, 0)) {
        }

        return true;
    }
}
