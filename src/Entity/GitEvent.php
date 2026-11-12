<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'git_event')]
#[ORM\UniqueConstraint(name: 'git_event_identity', columns: ['repository_id', 'event_key'])]
class GitEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function getId(): string
    {
        return $this->id;
    }

    #[ORM\ManyToOne(targetEntity: GitRepository::class)]
    #[ORM\JoinColumn(nullable: false)]
    private GitRepository $repository;

    public function getRepositoryId(): string
    {
        return $this->repository->getId();
    }

    #[ORM\Column(type: 'string', length: 255)]
    private string $eventKey;

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    #[ORM\Column(type: 'string', length: 32)]
    private string $kind;

    public function getKind(): string
    {
        return $this->kind;
    }

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sha = null;

    public function getSha(): ?string
    {
        return $this->sha;
    }

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $metadata = [];

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $observedAt;

    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

}
