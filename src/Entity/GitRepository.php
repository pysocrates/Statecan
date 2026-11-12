<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'git_repository')]
#[ORM\UniqueConstraint(name: 'git_repository_path', columns: ['path'])]
class GitRepository
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function getId(): string
    {
        return $this->id;
    }

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    public function getProjectId(): string
    {
        return $this->project->getId();
    }

    #[ORM\Column(type: 'string', length: 1024)]
    private string $path;

    public function getPath(): string
    {
        return $this->path;
    }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $refs = [];

    public function getRefs(): array
    {
        return $this->refs;
    }

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $observedAt = null;

    public function getObservedAt(): ?\DateTimeImmutable
    {
        return $this->observedAt;
    }

    public function __construct(string $id, Project $project, string $path)
    {
        $this->id = $id;
        $this->project = $project;
        $this->path = $path;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
