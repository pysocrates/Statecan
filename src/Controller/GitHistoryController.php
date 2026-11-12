<?php

namespace App\Controller;

use App\Dto\Observability\GitHistoryQuery;
use App\Observability\GitHistoryProjection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class GitHistoryController extends AbstractController
{
    public function __construct(private readonly GitHistoryProjection $projection)
    {
    }

    #[Route('/api/v1/observability/git', name: 'api_console_git', methods: ['GET'])]
    public function git(#[MapQueryString] GitHistoryQuery $filter = new GitHistoryQuery()): JsonResponse
    {
        return $this->json($this->projection->git($filter), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/dashboard/git', name: 'app_console_git', methods: ['GET'])]
    public function gitFragment(#[MapQueryString] GitHistoryQuery $filter = new GitHistoryQuery()): Response
    {
        return $this->render('observability/_git.html.twig', ['git' => $this->projection->git($filter)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/dashboard/git/commits/{sha}', name: 'app_console_git_commit', requirements: ['sha' => '[a-f0-9]{40,64}'], methods: ['GET'])]
    public function commit(string $sha, #[MapQueryString] GitHistoryQuery $filter = new GitHistoryQuery()): Response
    {
        $result = $this->projection->git(new GitHistoryQuery(project: $filter->project, repository: $filter->repository, q: $sha));
        $event = array_find($result['events'], static fn (array $event): bool => $event['sha'] === $sha && in_array($event['kind'], ['commit', 'merge'], true));
        if (!$event) {
            throw new NotFoundHttpException('Indexed commit not found in this repository.');
        }

        return $this->render('observability/_git_commit.html.twig', ['event' => $event, 'project' => $result['project']], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/api/v1/observability/history', name: 'api_console_history', methods: ['GET'])]
    public function history(#[MapQueryString] GitHistoryQuery $filter = new GitHistoryQuery()): JsonResponse
    {
        return $this->json($this->projection->history($filter), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/dashboard/history', name: 'app_console_history', methods: ['GET'])]
    public function historyFragment(#[MapQueryString] GitHistoryQuery $filter = new GitHistoryQuery()): Response
    {
        return $this->render('observability/_history.html.twig', ['history' => $this->projection->history($filter)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
