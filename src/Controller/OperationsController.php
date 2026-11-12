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

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json($this->monitor->health());
    }
}
