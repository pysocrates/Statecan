<?php

namespace App\Agent;

use App\Entity\TaskLease;
use App\Event\OperatorTaskMutation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class ProtectLeasedTask
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener]
    public function __invoke(OperatorTaskMutation $event): void
    {
        $lease = $this->em->getRepository(TaskLease::class)->findOneBy(['task' => $event->task]);
        if ($lease) {
            $this->em->refresh($lease);
            if ($lease->getExpiresAt() > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                throw new ConflictHttpException('Task has an active agent lease.');
            }
        }
    }
}
