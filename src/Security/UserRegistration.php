<?php

namespace App\Security;

use App\Entity\User;
use App\Runtime\RuntimeLockFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserRegistration
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: RuntimeLockFactory::class)] private LockFactory $locks,
    ) {
    }

    public function register(string $email, #[\SensitiveParameter] string $password): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        // All application instances serialize the initial-account decision in PostgreSQL.
        $lock = $this->locks->createLock('statecan.user-registration');
        $lock->acquire(true);

        try {
            return $this->em->wrapInTransaction(function () use ($user): User {
                if (0 === $this->em->getRepository(User::class)->count([])) {
                    $user->grantOperator();
                }

                $this->em->persist($user);

                return $user;
            });
        } finally {
            $lock->release();
        }
    }
}
