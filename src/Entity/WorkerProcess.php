<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Operational heartbeat projection maintained by Messenger lifecycle events. */
#[ORM\Entity]
#[ORM\Table(name: 'worker_process')]
class WorkerProcess
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 255)]
    private string $hostname;
    #[ORM\Column]
    private int $pid;
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $transports;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastProgressAt;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stoppedAt = null;
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $currentJobId = null;
    #[ORM\Column]
    private int $handledCount = 0;
}
