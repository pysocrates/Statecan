<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'agent')]
class Agent implements UserInterface
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;

    public function __construct(
        #[ORM\Column(length: 160)] private string $name,
        #[ORM\Column(length: 64, unique: true)] private string $tokenHash,
    ) {
        $this->id = bin2hex(random_bytes(18));
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getUserIdentifier(): string { return $this->id; }
    public function getRoles(): array { return ['ROLE_AGENT']; }
    public function eraseCredentials(): void {}
}
