<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'git_task_association')]
#[ORM\UniqueConstraint(name: 'git_association_identity', columns: ['repository_id', 'sha', 'task_id'])]
class GitTaskAssociation
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

    #[ORM\Column(type: 'string', length: 64)]
    private string $sha;

    public function getSha(): string
    {
        return $this->sha;
    }

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Task $task;

    public function getTaskId(): string
    {
        return $this->task->getId();
    }

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $sessionId = null;

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $agentId = null;

    public function getAgentId(): ?string
    {
        return $this->agentId;
    }

    #[ORM\Column(type: 'string', length: 32)]
    private string $source;

    public function getSource(): string
    {
        return $this->source;
    }

    #[ORM\Column(type: 'string', length: 32)]
    private string $confidence;

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    #[ORM\Column(type: 'string', length: 32)]
    private string $verificationStatus;

    public function getVerificationStatus(): string
    {
        return $this->verificationStatus;
    }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $observedAt;

    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

}
