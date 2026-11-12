<?php

namespace App\Controller;

use App\Dto\Core\{CreateProject, CreateTask, DeleteTask, ReviseTask, TransitionTask};
use App\Entity\{Project, StateEvent, Task, TaskContract};
use App\Service\{CoreConflict, CoreMutation, TaskService, TaskTransitionService};
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class CoreApiController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly CoreMutation $mutations, private readonly \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events) {}

    #[Route('/projects', methods: ['POST'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function createProject(#[MapRequestPayload(acceptFormat: 'json')] CreateProject $input, Request $request): JsonResponse
    {
        return $this->mutate($request, function () use ($input): array {
            $project = new Project($input->name);
            $this->em->persist($project);
            return [$this->project($project), 201];
        });
    }

    #[Route('/projects', methods: ['GET'])]
    public function projects(): JsonResponse
    {
        return $this->json(array_map($this->project(...), $this->em->getRepository(Project::class)->findBy(['archivedAt' => null], ['createdAt' => 'DESC'], 100)));
    }

    #[Route('/projects/{id}', methods: ['GET'])]
    public function getProject(string $id): JsonResponse
    {
        return $this->json($this->project($this->em->find(Project::class, $id) ?? throw $this->createNotFoundException()));
    }

    #[Route('/projects/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function updateProject(string $id, #[MapRequestPayload(acceptFormat: 'json')] CreateProject $input, Request $request): JsonResponse
    {
        return $this->mutate($request, function () use ($id, $input): array {
            $project = $this->em->find(Project::class, $id, LockMode::PESSIMISTIC_WRITE) ?? throw $this->createNotFoundException();
            if ($project->getArchivedAt()) { throw new CoreConflict('Project is archived.'); }
            $project->rename($input->name);
            return [$this->project($project), 200];
        });
    }

    #[Route('/projects/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function archiveProject(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function () use ($id): array {
            $project = $this->em->find(Project::class, $id, LockMode::PESSIMISTIC_WRITE) ?? throw $this->createNotFoundException();
            $active = $this->em->createQuery('SELECT COUNT(t.id) FROM App\\Entity\\Task t WHERE t.project = :project AND t.status NOT IN (:terminal)')->setParameters(['project' => $project, 'terminal' => ['completed', 'cancelled']])->getSingleScalarResult();
            if ($active) { throw new CoreConflict('Project still has active tasks.'); }
            $project->archive();
            return [$this->project($project), 200];
        });
    }

    #[Route('/tasks', methods: ['POST'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function createTask(#[MapRequestPayload(acceptFormat: 'json')] CreateTask $input, Request $request, TaskService $tasks): JsonResponse
    {
        return $this->mutate($request, fn () => [$this->task($tasks->create($input->projectId, $input->title, $input->contract, $this->getUser()->getUserIdentifier())), 201]);
    }

    #[Route('/tasks', methods: ['GET'])]
    public function tasks(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('project')) { $criteria['project'] = $request->query->get('project'); }
        if ($request->query->has('status')) { $criteria['status'] = $request->query->get('status'); }
        $limit = max(1, min(100, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));
        return $this->json(array_map($this->task(...), $this->em->getRepository(Task::class)->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset)));
    }

    #[Route('/tasks/{id}', methods: ['GET'])]
    public function getTask(string $id, Request $request): JsonResponse
    {
        $task = $this->em->find(Task::class, $id) ?? throw $this->createNotFoundException();
        $version = $request->query->getInt('contractVersion', $task->getContractVersion());
        $contract = $this->em->getRepository(TaskContract::class)->findOneBy(['task' => $task, 'version' => $version]) ?? throw $this->createNotFoundException('Unknown contract version.');
        return $this->json($this->task($task) + ['contract' => $contract->getDocument(), 'requestedContractVersion' => $version]);
    }

    #[Route('/tasks/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function reviseTask(string $id, #[MapRequestPayload(acceptFormat: 'json')] ReviseTask $input, Request $request, TaskService $tasks): JsonResponse
    {
        return $this->mutate($request, fn () => [$this->task($tasks->revise($id, $input->title, $input->contract, $input->expectedVersion, $this->getUser()->getUserIdentifier())), 200]);
    }

    #[Route('/tasks/{id}/transition', methods: ['POST'], priority: 20, condition: "not (request.headers.get('Authorization', '') matches '/^Bearer /i')")]
    #[IsGranted('ROLE_OPERATOR')]
    public function transition(string $id, #[MapRequestPayload(acceptFormat: 'json')] TransitionTask $input, Request $request, TaskTransitionService $transitions): JsonResponse
    {
        return $this->operatorTransition($id, $input->target, $input->expectedVersion, $request, $transitions);
    }

    #[Route('/tasks/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function deleteTask(string $id, #[MapRequestPayload(acceptFormat: 'json')] DeleteTask $input, Request $request, TaskTransitionService $transitions): JsonResponse
    {
        return $this->operatorTransition($id, 'cancelled', $input->expectedVersion, $request, $transitions);
    }

    private function operatorTransition(string $id, string $target, int $version, Request $request, TaskTransitionService $transitions): JsonResponse
    {
        return $this->mutate($request, function () use ($id, $target, $version, $transitions): array {
            $task = $this->em->find(Task::class, $id, LockMode::PESSIMISTIC_WRITE) ?? throw $this->createNotFoundException();
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $this->events->dispatch(new \App\Event\OperatorTaskMutation($task));
            if (!in_array($task->getStatus(), ['backlog', 'ready', 'blocked', 'failed'], true)) { throw new CoreConflict('Owned tasks require a fenced agent command.'); }
            return [$this->task($transitions->transition($id, $target, $version, $this->getUser()->getUserIdentifier())), 200];
        });
    }

    #[Route('/tasks/{id}/events', methods: ['GET'])]
    public function events(string $id, Request $request): JsonResponse
    {
        $task = $this->em->find(Task::class, $id) ?? throw $this->createNotFoundException();
        $events = $this->em->getRepository(StateEvent::class)->findBy(['task' => $task], ['version' => 'ASC'], 100, max(0, $request->query->getInt('offset', 0)));
        return $this->json(array_map(static fn (StateEvent $event) => ['id' => $event->getId(), 'from' => $event->getFromState(), 'to' => $event->getToState(), 'version' => $event->getVersion(), 'actorId' => $event->getActorId(), 'metadata' => $event->getMetadata(), 'createdAt' => $event->getCreatedAt()->format(DATE_ATOM)], $events));
    }

    private function mutate(Request $request, callable $operation): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) { return $this->json(['error' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key is required (1–128 letters, digits, dot, colon, underscore or hyphen).'], 400); }
        try {
            $scoped = hash('sha256', $this->getUser()->getUserIdentifier().'|'.$key);
            $hash = hash('sha256', $request->getMethod().' '.$request->getPathInfo().' '.$request->getContent());
            [$body, $status] = $this->mutations->execute($scoped, $hash, $operation);
            return $this->json($body, $status);
        } catch (CoreConflict|UniqueConstraintViolationException $e) {
            return $this->json(['error' => 'conflict', 'message' => $e instanceof CoreConflict ? $e->getMessage() : 'Concurrent request conflict; retry with the same key.'], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 422);
        }
    }

    private function project(Project $project): array
    {
        return ['id' => $project->getId(), 'name' => $project->getName(), 'createdAt' => $project->getCreatedAt()->format(DATE_ATOM), 'archivedAt' => $project->getArchivedAt()?->format(DATE_ATOM)];
    }

    private function task(Task $task): array
    {
        return ['id' => $task->getId(), 'projectId' => $task->getProject()->getId(), 'title' => $task->getTitle(), 'status' => $task->getStatus(), 'version' => $task->getVersion(), 'contractVersion' => $task->getContractVersion(), 'createdAt' => $task->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $task->getUpdatedAt()->format(DATE_ATOM)];
    }
}
