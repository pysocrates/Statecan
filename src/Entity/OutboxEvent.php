<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_event')]
#[ORM\Index(columns: ['published_at', 'created_at'])]
class OutboxEvent
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;
    public function __construct(
        #[ORM\Column(length: 100)] private string $type,
        #[ORM\Column(length: 36)] private string $aggregateId,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])] private array $payload,
    ) { $this->id = Uuid::v7()->toRfc4122(); $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function getId(): string { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getPayload(): array { return $this->payload; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function markPublished(): void { $this->publishedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
