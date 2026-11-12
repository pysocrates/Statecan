<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_contract')]
#[ORM\UniqueConstraint(columns: ['task_id', 'version'])]
class TaskContract
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private Task $task,
        #[ORM\Column] private int $version,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])] private array $document,
    ) { $this->id = Uuid::v7()->toRfc4122(); $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function getId(): string { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getVersion(): int { return $this->version; }
    public function getDocument(): array { return $this->document; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
