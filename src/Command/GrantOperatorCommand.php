<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:operator:grant', description: 'Grant operator permissions to an existing registered form user.')]
final readonly class GrantOperatorCommand
{
    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(SymfonyStyle $io, #[Argument] string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error('Register this user before granting operator permissions.');

            return 1;
        }
        $user->grantOperator();
        $this->em->flush();
        $io->success('Operator permissions granted. Sign in again to refresh the session.');

        return 0;
    }
}
