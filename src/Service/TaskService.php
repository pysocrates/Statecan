<?php

namespace App\Service;

use App\Entity\{Project, Task, TaskContract, TaskDependency};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TaskService
{
    public function __construct(private EntityManagerInterface $em, private ContractValidator $contracts, private TaskTransitionService $transitions, private \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events) {}

    public function create(string $projectId, string $title, array $contract, string $actorId, ?string $key = null, ?string $hash = null): Task
    {
        $this->contracts->validate($contract);
        return $this->em->wrapInTransaction(function () use ($projectId, $title, $contract, $actorId, $key, $hash): Task {
            if ($key && $task = $this->em->getRepository(Task::class)->findOneBy(['idempotencyKey' => $key])) {
                if ($task->getRequestHash() !== $hash) { throw new CoreConflict('Idempotency key was used for a different request.'); }
                return $task;
            }
            $project = $this->em->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);
            if (!$project || $project->getArchivedAt()) { throw new \InvalidArgumentException('Unknown or archived project.'); }
            $task = new Task($project, $title, $key, $hash);
            $this->em->persist($task);
            $this->em->persist(new TaskContract($task, 1, $contract));
            $this->dependencies($task, $contract['dependencies']);
            $this->transitions->record($task, null, $actorId);
            return $task;
        });
    }

    public function revise(string $id, string $title, ?array $contract, int $expectedVersion, string $actorId, array $metadata = []): Task
    {
        if (null !== $contract) { $this->contracts->validate($contract); }
        return $this->em->wrapInTransaction(function () use ($id, $title, $contract, $expectedVersion, $actorId, $metadata): Task {
            $task = $this->em->find(Task::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$task) { throw new \InvalidArgumentException('Unknown task.'); }
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $this->em->lock($task->getProject(), LockMode::PESSIMISTIC_WRITE);
            $this->events->dispatch(new \App\Event\OperatorTaskMutation($task));
            if ($task->getVersion() !== $expectedVersion) { throw new CoreConflict('Task version conflict.'); }
            if (!in_array($task->getStatus(), ['backlog', 'blocked'], true) || $task->getProject()->getArchivedAt()) { throw new CoreConflict('Only unowned backlog/blocked tasks may be revised.'); }
            if (null !== $contract) {
                foreach ($this->em->getRepository(TaskDependency::class)->findBy(['task' => $task]) as $dependency) { $this->em->remove($dependency); }
                $this->em->flush();
                $this->dependencies($task, $contract['dependencies']);
            }
            $task->revise($title, null !== $contract);
            if (null !== $contract) { $this->em->persist(new TaskContract($task, $task->getContractVersion(), $contract)); }
            $this->transitions->record($task, $task->getStatus(), $actorId, $metadata);
            return $task;
        });
    }

    private function dependencies(Task $task, array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            $dependency = $this->em->find(Task::class, $id);
            if (!$dependency || $dependency->getProject()->getId() !== $task->getProject()->getId()) { throw new \InvalidArgumentException('Dependencies must reference tasks in the same project.'); }
            if ($this->reaches($dependency, $task->getId(), [])) { throw new CoreConflict('Dependency cycle.'); }
            $this->em->persist(new TaskDependency($task, $dependency));
        }
    }

    private function reaches(Task $task, string $target, array $visited): bool
    {
        if ($task->getId() === $target) { return true; }
        if (isset($visited[$task->getId()])) { return false; }
        $visited[$task->getId()] = true;
        foreach ($this->em->getRepository(TaskDependency::class)->findBy(['task' => $task]) as $dependency) {
            if ($this->reaches($dependency->getDependsOn(), $target, $visited)) { return true; }
        }
        return false;
    }
}
