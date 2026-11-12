<?php

namespace App\Controller;

use App\Runtime\RuntimeMonitor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/operations')]
#[IsGranted('ROLE_OPERATOR')]
final class OperationsController extends AbstractController
{
    public function __construct(private readonly RuntimeMonitor $monitor)
    {
    }

    #[Route('/jobs', methods: ['GET'])]
    public function jobs(): JsonResponse
    {
        return $this->json(['jobs' => $this->monitor->jobs()]);
    }

    #[Route('/queues', methods: ['GET'])]
    public function queues(): JsonResponse
    {
        return $this->json(['queues' => $this->monitor->queues()]);
    }

    #[Route('/workers', methods: ['GET'])]
    public function workers(): JsonResponse
    {
        return $this->json(['workers' => $this->monitor->workers()]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        try {
            $health = $this->monitor->health();
        } catch (\Doctrine\DBAL\Exception) {
            return $this->json(['status' => 'unavailable', 'database' => 'unavailable', 'worker_liveness' => 'unknown'], 503);
        }

        return $this->json($health, 'ok' === $health['status'] ? 200 : 503);
    }
}
