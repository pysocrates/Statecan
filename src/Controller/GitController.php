<?php

namespace App\Controller;

use App\Dto\Git\GitPage;
use App\Dto\Git\RegisterRepository;
use App\Git\GitCatalog;
use App\Git\GitQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
#[Route('/api/v1', format: 'json')]
final class GitController extends AbstractController
{
    public function __construct(private readonly GitCatalog $catalog, private readonly GitQuery $query)
    {
    }

    #[Route('/repositories', name: 'api_git_register', methods: ['POST'])]
    public function register(#[MapRequestPayload(acceptFormat: 'json')] RegisterRepository $input, Request $request): JsonResponse
    {
        try {
            $result = $this->catalog->register($input->projectId, $input->path, $this->key($request));
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->json($result, 202);
    }

    #[Route('/repositories/{id}/sync', name: 'api_git_sync', methods: ['POST'])]
    public function sync(string $id, Request $request): JsonResponse
    {
        return $this->json($this->catalog->sync($id, $this->key($request)), 202);
    }

    #[Route('/repositories/{id}/events', name: 'api_git_events', methods: ['GET'])]
    public function events(string $id, #[MapQueryString] GitPage $page = new GitPage()): JsonResponse
    {
        return $this->json($this->query->events($id, $page->limit, $page->offset));
    }

    #[Route('/repositories/{id}/graph', name: 'api_git_graph', methods: ['GET'])]
    public function graph(string $id, #[MapQueryString] GitPage $page = new GitPage()): JsonResponse
    {
        return $this->json($this->query->graph($id, $page->limit));
    }

    #[Route('/tasks/{id}/git', name: 'api_git_task', methods: ['GET'])]
    public function task(string $id, #[MapQueryString] GitPage $page = new GitPage()): JsonResponse
    {
        return $this->json($this->query->task($id, $page->limit));
    }

    private function key(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }

        return $key;
    }
}
