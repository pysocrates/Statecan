<?php

namespace App\Runtime;

use App\Message\OutboxMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final readonly class JobMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection,
        private RuntimeLockFactory $locks,
        private LoggerInterface $logger,
        private WorkerHeartbeat $heartbeat,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if (null === $received) {
            return $stack->next()->handle($envelope, $stack);
        }
        $message = $envelope->getMessage();
        $id = self::jobId($envelope);
        $correlationId = $message instanceof OutboxMessage ? $message->correlationId : $id;
        $context = ['job_id' => $id, 'correlation_id' => $correlationId, 'message_type' => $message::class];
        // Session advisory lock is released even on SIGKILL/connection loss; no long transaction.
        $lock = $this->locks->createLock('statecan.job.'.$id);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Job is already running.', retryDelay: 1000, forceRetry: false);
        }
        try {
            if ('completed' === $this->connection->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id])) {
                return $envelope;
            }
            $this->connection->executeStatement("INSERT INTO background_job (id, message_type, status, attempts, correlation_id, created_at, started_at) VALUES (?, ?, 'processing', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (id) DO UPDATE SET status = 'processing', attempts = background_job.attempts + 1, started_at = CURRENT_TIMESTAMP, finished_at = NULL, error = NULL", [$id, $message::class, $correlationId]);
            $this->connection->executeStatement('UPDATE background_job SET worker_id = ?, queue_name = ? WHERE id = ?', [$this->heartbeat->id(), $received->getTransportName(), $id]);
            $this->logger->info('Job started', $context);
            try {
                $result = $stack->next()->handle($envelope, $stack);
                $this->connection->executeStatement("UPDATE background_job SET status = 'completed', finished_at = CURRENT_TIMESTAMP, error = NULL WHERE id = ?", [$id]);
                $this->logger->info('Job completed', $context);

                return $result;
            } catch (\Throwable $error) {
                $this->connection->executeStatement("UPDATE background_job SET status = 'failed', finished_at = CURRENT_TIMESTAMP, error = ? WHERE id = ?", [substr($error->getMessage(), 0, 2000), $id]);
                $this->logger->error('Job failed', $context + ['exception' => $error]);
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }

    public static function jobId(Envelope $envelope): string
    {
        $message = $envelope->getMessage();
        if ($message instanceof JobMessage) {
            return $message->getJobId();
        }
        $transportId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if (null === $transportId) {
            throw new \LogicException('Async messages require JobMessage or a transport message identifier.');
        }

        return 'transport:'.$transportId;
    }
}
