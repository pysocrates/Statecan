<?php

namespace App\Service;

use App\Entity\ApiMutation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CoreMutation
{
    public function __construct(private EntityManagerInterface $em) {}

    public function execute(string $key, string $requestHash, callable $operation): array
    {
        return $this->em->wrapInTransaction(function () use ($key, $requestHash, $operation): array {
            $record = $this->em->find(ApiMutation::class, $key, LockMode::PESSIMISTIC_WRITE);
            if ($record) {
                if ($record->getRequestHash() !== $requestHash) { throw new CoreConflict('Idempotency key was used for a different request.'); }
                return [$record->getResponse(), $record->getStatus()];
            }
            $record = new ApiMutation($key, $requestHash);
            $this->em->persist($record);
            // Reserve the unique key before any domain changes; racing requests receive a retryable 409.
            $this->em->flush();
            [$body, $status] = $operation();
            $record->finish($body, $status);
            return [$body, $status];
        });
    }
}
