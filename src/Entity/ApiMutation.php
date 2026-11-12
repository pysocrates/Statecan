<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable replay record for authenticated core API mutation requests. */
#[ORM\Entity]
#[ORM\Table(name: 'api_mutation')]
class ApiMutation
{
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $response = [];
    #[ORM\Column]
    private int $status = 200;
    public function __construct(
        #[ORM\Id, ORM\Column(length: 64)] private string $id,
        #[ORM\Column(length: 64)] private string $requestHash,
    ) {}
    public function getRequestHash(): string { return $this->requestHash; }
    public function getResponse(): array { return $this->response; }
    public function getStatus(): int { return $this->status; }
    public function finish(array $response, int $status): void { $this->response = $response; $this->status = $status; }
}
