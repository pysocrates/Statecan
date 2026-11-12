<?php

namespace App\Controller;

use App\Agent\LeaseInput;
use App\Agent\LeaseService;
use App\Agent\SessionService;
use App\Entity\Agent;
use App\Entity\CompletionReceipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
final class AgentApiController extends AbstractController
{
    #[Route('/agents/sessions', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function register(Request $request, SessionService $sessions): JsonResponse
    {
        return $this->json($sessions->register($this->agent(), $this->key($request)), 201);
    }

    #[Route('/agents/sessions/{id}/heartbeat', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function heartbeat(string $id, Request $request, SessionService $sessions): JsonResponse
    {
        return $this->json($sessions->heartbeat($this->agent(), $id, $this->key($request)));
    }

    #[Route('/agents/sessions/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_AGENT')]
    public function session(string $id, SessionService $sessions): JsonResponse
    {
        return $this->json($sessions->view($sessions->owned($this->agent(), $id)));
    }

    #[Route('/tasks/{id}/{operation}', requirements: ['operation' => 'claim|heartbeat|transition|complete'], methods: ['POST'], condition: "request.headers.get('Authorization', '') matches '/^Bearer /i'", priority: 10)]
    #[IsGranted('ROLE_AGENT')]
    public function command(string $id, string $operation, Request $request, #[MapRequestPayload(acceptFormat: 'json')] LeaseInput $input, LeaseService $leases): JsonResponse
    {
        return $this->json($leases->command($this->agent(), $id, $operation, $this->key($request), $input), 'complete' === $operation ? 202 : 200);
    }

    #[Route('/tasks/{id}/receipts', methods: ['GET'])]
    public function receipts(string $id, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $receipts = $em->getRepository(CompletionReceipt::class)->findBy(['task' => $id], ['createdAt' => 'DESC'], 100);

        return $this->json(array_map(static fn (CompletionReceipt $r) => [
            'id' => $r->getId(), 'taskId' => $r->getTask()->getId(), 'sessionId' => $r->getSession()->getId(),
            'contractVersion' => $r->getContractVersion(), 'leaseGeneration' => $r->getLeaseGeneration(),
            'status' => $r->getStatus(), 'sha256' => $r->getHash(), 'storageKey' => $r->getStorageKey(),
            'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
        ], $receipts));
    }

    private function agent(): Agent
    {
        $agent = $this->getUser();
        if (!$agent instanceof Agent) {
            throw $this->createAccessDeniedException();
        }

        return $agent;
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
