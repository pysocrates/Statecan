<?php

namespace App\Service;

use App\Entity\{OutboxEvent, StateEvent, Task, TaskDependency};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Trusted application boundary; agent callers must validate leases in the same enclosing transaction. */
final readonly class TaskTransitionService
{
    private const TRANSITIONS = [
        'backlog' => ['ready', 'blocked', 'cancelled'],
        'ready' => ['claimed', 'blocked', 'cancelled'],
        'claimed' => ['running', 'ready', 'blocked', 'failed', 'cancelled'],
        'running' => ['ready', 'blocked', 'awaiting_review', 'completion_pending_evidence', 'failed', 'cancelled'],
        'blocked' => ['ready', 'cancelled'],
        'awaiting_review' => ['ready', 'running', 'completion_pending_evidence', 'failed', 'cancelled'],
        'completion_pending_evidence' => ['completed', 'failed'],
        'failed' => ['ready', 'cancelled'],
        'completed' => [], 'cancelled' => [],
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function transition(string $taskId, string $target, int $expectedVersion, string $actorId, array $metadata = []): Task
    {
        return $this->em->wrapInTransaction(function () use ($taskId, $target, $expectedVersion, $actorId, $metadata): Task {
            $task = $this->em->find(Task::class, $taskId, LockMode::PESSIMISTIC_WRITE);
            if (!$task) { throw new \InvalidArgumentException('Unknown task.'); }
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $key = $metadata['idempotencyKey'] ?? null;
            if ($key && $event = $this->em->getRepository(StateEvent::class)->findOneBy(['idempotencyKey' => $key])) {
                if ($event->getTask()->getId() !== $taskId || $event->getToState() !== $target || $event->getActorId() !== $actorId || ($event->getMetadata()['requestHash'] ?? null) !== ($metadata['requestHash'] ?? null)) {
                    throw new CoreConflict('Idempotency key was used for a different command.');
                }
                return $task;
            }
            if ($task->getVersion() !== $expectedVersion) { throw new CoreConflict('Task version conflict.'); }
            if (!in_array($target, self::TRANSITIONS[$task->getStatus()] ?? [], true)) { throw new CoreConflict('Transition is not permitted.'); }
            if ($task->getProject()->getArchivedAt()) { throw new CoreConflict('Project is archived.'); }
            if (in_array($target, ['ready', 'claimed', 'running', 'completion_pending_evidence', 'completed'], true)) {
                foreach ($this->em->getRepository(TaskDependency::class)->findBy(['task' => $task]) as $dependency) {
                    $this->em->refresh($dependency->getDependsOn(), LockMode::PESSIMISTIC_READ);
                    if ('completed' !== $dependency->getDependsOn()->getStatus()) { throw new CoreConflict('Task dependencies are incomplete.'); }
                }
            }
            // Finalization is reserved for the receipt materializer, with a verified receipt in its enclosing transaction.
            if ('completed' === $target && empty($metadata['verifiedReceiptId'])) { throw new CoreConflict('Verified receipt is required.'); }
            $from = $task->getStatus();
            $task->applyTransition($target);
            $this->record($task, $from, $actorId, $metadata, $key);
            return $task;
        });
    }

    public function record(Task $task, ?string $from, string $actorId, array $metadata = [], ?string $key = null): void
    {
        $event = new StateEvent($task, $from, $task->getStatus(), $task->getVersion(), $actorId, $metadata, $key);
        $this->em->persist($event);
        $this->em->persist(new OutboxEvent('task.transitioned', $task->getId(), ['taskId' => $task->getId(), 'projectId' => $task->getProject()->getId(), 'version' => $task->getVersion(), 'eventId' => $event->getId()]));
    }
}
