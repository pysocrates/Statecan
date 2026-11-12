<?php

namespace App\Agent;

use App\Entity\Agent;
use App\Entity\AgentSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class SessionService
{
    public function __construct(private EntityManagerInterface $em, private AgentRequests $requests) {}

    public function register(Agent $agent, string $key): array
    {
        return $this->requests->execute($agent, $key, ['register'], function () use ($agent, $key) {
            $session = new AgentSession($agent, $key);
            $this->em->persist($session);

            return $this->view($session);
        });
    }

    public function owned(Agent $agent, string $id): AgentSession
    {
        $session = $this->em->find(AgentSession::class, $id);
        if (!$session || $session->getAgent()->getId() !== $agent->getId()) {
            throw new NotFoundHttpException('Session not found.');
        }

        return $session;
    }

    public function heartbeat(Agent $agent, string $id, string $key): array
    {
        return $this->requests->execute($agent, $key, ['session-heartbeat', $id], function () use ($agent, $id) {
            $session = $this->owned($agent, $id);
            $session->heartbeat();

            return $this->view($session);
        });
    }

    public function view(AgentSession $session): array
    {
        return ['id' => $session->getId(), 'agentId' => $session->getAgent()->getId(), 'createdAt' => $session->getCreatedAt()->format(DATE_ATOM), 'lastHeartbeatAt' => $session->getLastHeartbeatAt()->format(DATE_ATOM)];
    }
}
