<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'task_lease')]
#[ORM\Index(columns: ['expires_at'])]
class TaskLease
{
    public function __construct(
        #[ORM\Id, ORM\OneToOne] #[ORM\JoinColumn(nullable: false)] private Task $task,
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private AgentSession $session,
        #[ORM\Column(length: 64)] private string $tokenHash,
        #[ORM\Column] private int $generation,
        #[ORM\Column] private \DateTimeImmutable $expiresAt,
        #[ORM\Column(length: 128)] private string $requestKey,
    ) {}

    public function getTask(): Task { return $this->task; }
    public function getSession(): AgentSession { return $this->session; }
    public function getGeneration(): int { return $this->generation; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getRequestKey(): string { return $this->requestKey; }
    public function matches(string $token): bool { return hash_equals($this->tokenHash, hash('sha256', $token)); }
    public function renew(): void { $this->expiresAt = new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')); }
    public function expire(): void { $this->expiresAt = new \DateTimeImmutable('@0'); }
    public function reclaim(AgentSession $session, string $token, string $key): void
    {
        $this->session = $session;
        $this->tokenHash = hash('sha256', $token);
        ++$this->generation;
        $this->requestKey = $key;
        $this->renew();
    }
}
