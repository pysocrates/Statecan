<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'completion_receipt')]
#[ORM\UniqueConstraint(name: 'receipt_request_key', columns: ['task_id', 'request_key'])]
class CompletionReceipt
{
    #[ORM\Id, ORM\Column(length: 36)] private string $id;
    #[ORM\Column(length: 24)] private string $status = 'pending';
    #[ORM\Column(length: 64, nullable: true)] private ?string $hash = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'text')] private string $canonicalContent;

    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private Task $task,
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private AgentSession $session,
        #[ORM\Column] private int $leaseGeneration,
        #[ORM\Column] private int $contractVersion,
        #[ORM\Column(length: 128)] private string $requestKey,
        string $id, string $canonicalContent,
    ) {
        $this->id = $id;
        $this->canonicalContent = $canonicalContent;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getId(): string { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getSession(): AgentSession { return $this->session; }
    public function getLeaseGeneration(): int { return $this->leaseGeneration; }
    public function getContractVersion(): int { return $this->contractVersion; }
    public function getStatus(): string { return $this->status; }
    public function getHash(): ?string { return $this->hash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStorageKey(): string { return $this->id.'.json'; }
    public function getCanonicalContent(): string { return $this->canonicalContent; }
    public function verify(string $hash): void { $this->hash = $hash; $this->status = 'verified'; }
    public function markInconsistent(): void { $this->status = 'inconsistent'; }
}
