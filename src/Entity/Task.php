<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task')]
#[ORM\Index(columns: ['project_id', 'status'])]
class Task
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 40)]
    private string $status = 'backlog';
    #[ORM\Column]
    private int $version = 1;
    #[ORM\Column]
    private int $contractVersion = 1;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column(nullable: true, length: 128, unique: true)]
    private ?string $idempotencyKey;
    #[ORM\Column(nullable: true)]
    private ?string $requestHash;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private Project $project,
        #[ORM\Column(length: 200)] private string $title,
        ?string $idempotencyKey = null, ?string $requestHash = null,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
    }
    public function getId(): string { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getTitle(): string { return $this->title; }
    public function getStatus(): string { return $this->status; }
    public function getVersion(): int { return $this->version; }
    public function getContractVersion(): int { return $this->contractVersion; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getRequestHash(): ?string { return $this->requestHash; }
    /** Internal domain mutation: callers must hold the task row lock and write ledger/outbox. */
    public function applyTransition(string $status): void { $this->status = $status; $this->touch(); }
    public function revise(string $title, bool $newContract): void { $this->title = $title; if ($newContract) { ++$this->contractVersion; } $this->touch(); }
    private function touch(): void { ++$this->version; $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
