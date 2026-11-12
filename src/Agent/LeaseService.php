<?php

namespace App\Agent;

use App\Entity\Agent;
use App\Entity\CompletionReceipt;
use App\Entity\OutboxEvent;
use App\Entity\Task;
use App\Entity\TaskLease;
use App\Receipt\CanonicalJson;
use App\Service\TaskTransitionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LeaseService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentRequests $requests,
        private SessionService $sessions,
        private TaskTransitionService $transitions,
        private CanonicalJson $canonical,
    ) {}

    public function command(Agent $agent, string $taskId, string $operation, string $key, LeaseInput $input): array
    {
        return $this->requests->execute($agent, $key, [$taskId, $operation, (array) $input], function () use ($agent, $taskId, $operation, $key, $input) {
            $task = $this->em->find(Task::class, $taskId, LockMode::PESSIMISTIC_WRITE);
            if (!$task) {
                throw new NotFoundHttpException('Task not found.');
            }
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $session = $this->sessions->owned($agent, $input->sessionId);
            $lease = $this->em->getRepository(TaskLease::class)->findOneBy(['task' => $task]);
            if ($lease) {
                $this->em->refresh($lease);
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($task->getVersion() !== $input->expectedVersion) {
                throw new ConflictHttpException('Task version conflict.');
            }
            if ('claim' === $operation) {
                if ($lease && $lease->getExpiresAt() > $now) {
                    throw new ConflictHttpException('Task already has a live lease.');
                }
                if ('ready' !== $task->getStatus()) {
                    throw new ConflictHttpException('Task must be ready before claiming.');
                }
                if ($lease) {
                    $lease->reclaim($session, $input->token, $key);
                } else {
                    $lease = new TaskLease($task, $session, hash('sha256', $input->token), 1, $now, $key);
                    $lease->renew();
                    $this->em->persist($lease);
                }
                $this->transitions->transition($taskId, 'claimed', $task->getVersion(), $agent->getId(), ['sessionId' => $session->getId(), 'generation' => $lease->getGeneration()]);
            } else {
                if (!$lease || $lease->getExpiresAt() <= $now || $lease->getSession()->getId() !== $session->getId() || $lease->getGeneration() !== $input->generation || !$lease->matches($input->token)) {
                    throw new ConflictHttpException('Lease is expired or fencing credentials do not match.');
                }
                if ('heartbeat' === $operation) {
                    $lease->renew();
                    $session->heartbeat();
                } elseif ('transition' === $operation) {
                    if (!$input->target) {
                        throw new ConflictHttpException('A target state is required.');
                    }
                    $this->transitions->transition($taskId, $input->target, $task->getVersion(), $agent->getId(), ['sessionId' => $session->getId(), 'generation' => $lease->getGeneration()]);
                    if (in_array($input->target, ['ready', 'failed'], true)) {
                        $lease->expire();
                    }
                } elseif ('complete' === $operation) {
                    if ('' === trim($input->result)) {
                        throw new ConflictHttpException('Completion result is required.');
                    }
                    $id = bin2hex(random_bytes(18));
                    $content = $this->canonical->encode([
                        'schemaVersion' => 1, 'canonicalization' => 'statecan-json-v1', 'id' => $id,
                        'taskId' => $taskId, 'contractVersion' => $task->getContractVersion(),
                        'sessionId' => $session->getId(), 'agentId' => $agent->getId(),
                        'leaseGeneration' => $lease->getGeneration(), 'result' => $input->result,
                        'evidence' => $input->evidence, 'reportedAt' => $now->format(DATE_ATOM),
                        'provenance' => 'authenticated-agent-report', 'verification' => 'server-hash-on-materialization',
                    ]);
                    $receipt = new CompletionReceipt($task, $session, $lease->getGeneration(), $task->getContractVersion(), $key, $id, $content);
                    $this->em->persist($receipt);
                    $this->transitions->transition($taskId, 'completion_pending_evidence', $task->getVersion(), $agent->getId(), ['receiptId' => $id, 'sessionId' => $session->getId(), 'generation' => $lease->getGeneration()]);
                    $this->em->persist(new OutboxEvent('receipt.requested', $id, ['receiptId' => $id, 'version' => 1]));

                    return ['receiptId' => $id, 'status' => 'pending', 'taskVersion' => $task->getVersion()];
                }
            }

            return ['taskId' => $taskId, 'status' => $task->getStatus(), 'version' => $task->getVersion(), 'generation' => $lease->getGeneration(), 'expiresAt' => $lease->getExpiresAt()->format(DATE_ATOM)];
        });
    }

    public function recoverExpired(int $limit = 100): int
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT task_id FROM task_lease WHERE expires_at < CURRENT_TIMESTAMP AND expires_at > :epoch LIMIT :limit', ['epoch' => '1970-01-01 00:00:00', 'limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $count = 0;
        foreach ($ids as $id) {
            $this->em->wrapInTransaction(function () use ($id, &$count) {
                $task = $this->em->find(Task::class, $id, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
                $lease = $this->em->getRepository(TaskLease::class)->findOneBy(['task' => $task]);
                $this->em->refresh($lease);
                if ($lease->getExpiresAt() >= new \DateTimeImmutable() || $lease->getExpiresAt()->getTimestamp() <= 0) {
                    return;
                }
                if (in_array($task->getStatus(), ['claimed', 'running', 'blocked', 'awaiting_review'], true)) {
                    $this->transitions->transition($id, 'ready', $task->getVersion(), 'lease-recovery', ['expiredGeneration' => $lease->getGeneration()]);
                }
                $lease->expire();
                ++$count;
            });
        }

        return $count;
    }
}
