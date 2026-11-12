<?php

namespace App\Controller;

use App\Observability\ComponentProjection;
use App\Observability\ObservationFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class ConsoleComponentController extends AbstractController
{
    #[Route('/dashboard/components/{component}', name: 'app_dashboard_component', requirements: ['component' => 'operations|work'], methods: ['GET'])]
    public function component(string $component, #[MapQueryString] ObservationFilter $filter, ComponentProjection $projection): Response
    {
        return $this->render('observability/_'.$component.'.html.twig', ['snapshot' => $projection->read($component, $filter)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/api/v1/observability/components/{component}', name: 'api_observability_component', requirements: ['component' => 'operations|work'], methods: ['GET'])]
    public function data(string $component, #[MapQueryString] ObservationFilter $filter, ComponentProjection $projection): JsonResponse
    {
        return $this->json($projection->read($component, $filter), headers: ['Cache-Control' => 'private, no-store']);
    }
}
