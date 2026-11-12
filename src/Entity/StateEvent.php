<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'state_event')]
#[ORM\UniqueConstraint(columns: ['task_id', 'version'])]
class StateEvent
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private Task $task,
        #[ORM\Column(length: 40, nullable: true)] private ?string $fromState,
        #[ORM\Column(length: 40)] private string $toState,
        #[ORM\Column] private int $version,
        #[ORM\Column(length: 180)] private string $actorId,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])] private array $metadata = [],
        #[ORM\Column(length: 128, unique: true, nullable: true)] private ?string $idempotencyKey = null,
    ) { $this->id = Uuid::v7()->toRfc4122(); $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function getId(): string { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getFromState(): ?string { return $this->fromState; }
    public function getToState(): string { return $this->toState; }
    public function getVersion(): int { return $this->version; }
    public function getActorId(): string { return $this->actorId; }
    public function getMetadata(): array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
