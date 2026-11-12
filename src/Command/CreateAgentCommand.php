<?php

namespace App\Command;

use App\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:agent:create', description: 'Provision an agent and display its bearer token once.')]
final readonly class CreateAgentCommand
{
    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(SymfonyStyle $io, #[Argument] string $name): int
    {
        $token = bin2hex(random_bytes(32));
        $agent = new Agent($name, hash('sha256', $token));
        $this->em->persist($agent);
        $this->em->flush();
        $io->writeln(['Agent: '.$agent->getId(), 'Token: '.$token]);

        return Command::SUCCESS;
    }
}
