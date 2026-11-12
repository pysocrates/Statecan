<?php

namespace App\Agent;

use App\Entity\Agent;
use App\Entity\AgentOperation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class AgentRequests
{
    public function __construct(private EntityManagerInterface $em) {}

    public function execute(Agent $agent, string $key, array $input, callable $operation): array
    {
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return $this->em->wrapInTransaction(function () use ($agent, $key, $fingerprint, $operation) {
            $this->em->lock($agent, LockMode::PESSIMISTIC_WRITE);
            $previous = $this->em->getRepository(AgentOperation::class)->findOneBy(['agent' => $agent, 'requestKey' => $key]);
            if ($previous) {
                if (!hash_equals($previous->getFingerprint(), $fingerprint)) {
                    throw new ConflictHttpException('Idempotency key reused with different input.');
                }

                return $previous->getResponse();
            }
            $response = $operation();
            $this->em->persist(new AgentOperation($agent, $key, $fingerprint, $response));

            return $response;
        });
    }
}
