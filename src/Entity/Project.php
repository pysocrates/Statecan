<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'project')]
class Project
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true, length: 128, unique: true)]
    private ?string $idempotencyKey;
    #[ORM\Column(nullable: true)]
    private ?string $requestHash;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(#[ORM\Column(length: 200)] private string $name, ?string $idempotencyKey = null, ?string $requestHash = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
    }
    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function getRequestHash(): ?string { return $this->requestHash; }
    public function rename(string $name): void { $this->name = $name; }
    public function archive(): void { $this->archivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
