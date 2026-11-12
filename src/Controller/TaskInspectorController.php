<?php

namespace App\Controller;

use App\Dto\Observability\TaskInspectorQuery;
use App\Observability\TaskInspectorProjection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class TaskInspectorController extends AbstractController
{
    #[Route('/dashboard/tasks/{id}/inspector', name: 'app_task_inspector', requirements: ['id' => '[a-fA-F0-9-]{36}'], methods: ['GET'])]
    public function fragment(string $id, #[MapQueryString] TaskInspectorQuery $query, TaskInspectorProjection $projection): Response
    {
        return $this->render('observability/_inspector.html.twig', ['inspector' => $projection->read($id, $query)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/api/v1/observability/tasks/{id}/inspector', name: 'api_task_inspector', requirements: ['id' => '[a-fA-F0-9-]{36}'], methods: ['GET'], format: 'json')]
    public function jsonInspector(string $id, #[MapQueryString] TaskInspectorQuery $query, TaskInspectorProjection $projection): JsonResponse
    {
        return $this->json($projection->read($id, $query), headers: ['Cache-Control' => 'private, no-store']);
    }
}
