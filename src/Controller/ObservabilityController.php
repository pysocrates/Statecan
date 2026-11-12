<?php

namespace App\Controller;

use App\Observability\ActivityStream;
use App\Observability\ComponentProjection;
use App\Observability\DashboardProjection;
use App\Observability\ObservationFilter;
use App\Observability\TaskInspectorProjection;
use App\Dto\Observability\TaskInspectorQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class ObservabilityController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(#[MapQueryString] ObservationFilter $filter, DashboardProjection $projection, TaskInspectorProjection $inspector): Response
    {
        return $this->render('observability/dashboard.html.twig', [
            'snapshot' => $projection->snapshot($filter),
            'initial_inspector' => null === $filter->task ? null : $inspector->read($filter->task, new TaskInspectorQuery(project: $filter->project, page: $filter->page)),
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/dashboard/fragment', name: 'app_dashboard_fragment', methods: ['GET'])]
    public function fragment(#[MapQueryString] ObservationFilter $filter, DashboardProjection $projection): Response
    {
        return $this->render('observability/_snapshot.html.twig', ['snapshot' => $projection->snapshot($filter)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/api/v1/observability/snapshot', name: 'api_observability_snapshot', methods: ['GET'])]
    public function snapshot(#[MapQueryString] ObservationFilter $filter, DashboardProjection $projection): JsonResponse
    {
        return $this->json($projection->snapshot($filter), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/v1/observability/receipts/{id}', name: 'api_observability_receipt', methods: ['GET'])]
    public function receipt(string $id, DashboardProjection $projection): JsonResponse
    {
        return $this->json($projection->receipt($id), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/v1/observability/events', name: 'api_observability_events', methods: ['GET'])]
    public function events(#[MapQueryString] ObservationFilter $filter, Request $request, ComponentProjection $projection, ActivityStream $stream): EventStreamResponse
    {
        // Validate selections before sending stream headers; the stream reads again after LISTEN.
        $projection->context($filter);
        // Release the form-login session before streaming so refresh requests can run concurrently.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        return new EventStreamResponse(static fn (): iterable => $stream->events($filter, $request->headers->get('Last-Event-ID')));
    }
}
