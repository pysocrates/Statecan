<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent_operation')]
#[ORM\UniqueConstraint(name: 'agent_operation_key', columns: ['agent_id', 'request_key'])]
class AgentOperation
{
    #[ORM\Id, ORM\Column(length: 36)] private string $id;

    public function __construct(
        #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false)] private Agent $agent,
        #[ORM\Column(length: 128)] private string $requestKey,
        #[ORM\Column(length: 64)] private string $fingerprint,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])] private array $response,
    ) {
        $this->id = bin2hex(random_bytes(18));
    }

    public function getFingerprint(): string { return $this->fingerprint; }
    public function getResponse(): array { return $this->response; }
}
