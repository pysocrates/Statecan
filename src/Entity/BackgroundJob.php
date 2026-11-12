<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Operational projection maintained by the runtime's atomic DBAL upserts. */
#[ORM\Entity]
#[ORM\Table(name: 'background_job')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'background_job_status_created_idx')]
class BackgroundJob
{
    #[ORM\Id, ORM\Column(length: 128)]
    private string $id;
    #[ORM\Column(length: 255)]
    private string $messageType;
    #[ORM\Column(length: 20)]
    private string $status;
    #[ORM\Column]
    private int $attempts;
    #[ORM\Column(length: 128)]
    private string $correlationId;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $workerId = null;
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $queueName = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
