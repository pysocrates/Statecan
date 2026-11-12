<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent_session')]
#[ORM\UniqueConstraint(name: 'session_registration_key', columns: ['agent_id', 'request_key'])]
class AgentSession
{
    #[ORM\Id, ORM\Column(length: 36)] private string $id;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $lastHeartbeatAt;

    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private Agent $agent,
        #[ORM\Column(length: 128)] private string $requestKey,
    ) {
        $this->id = bin2hex(random_bytes(18));
        $this->createdAt = $this->lastHeartbeatAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string { return $this->id; }
    public function getAgent(): Agent { return $this->agent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastHeartbeatAt(): \DateTimeImmutable { return $this->lastHeartbeatAt; }
    public function heartbeat(): void { $this->lastHeartbeatAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
