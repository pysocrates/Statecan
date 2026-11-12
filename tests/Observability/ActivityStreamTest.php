<?php

namespace App\Tests\Observability;

use App\Entity\Project;
use App\Observability\ActivityStream;
use App\Observability\ObservationFilter;
use App\Observability\PostgresActivityListener;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActivityStreamTest extends KernelTestCase
{
    public function testCommittedChangePushesAnotherEventOnTheSameStream(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $project = new Project('Before notification');
        $em->persist($project);
        $em->flush();
        $listener = $container->get(PostgresActivityListener::class);
        $subscription = $listener->subscribe();

        try {
            $events = $container->get(ActivityStream::class)->events(new ObservationFilter(project: $project->getId()), null);
            $initial = $events->current();
            self::assertSame('refresh', $initial->getType());

            $db->beginTransaction();
            $db->update('project', ['name' => 'After notification'], ['id' => $project->getId()]);
            self::assertFalse($listener->wait($subscription, 10), 'An uncommitted change must not wake clients.');
            $db->commit();
            self::assertTrue($listener->wait($subscription, 1000));

            // Resume the existing open generator: no HTTP reconnect or snapshot polling.
            $events->next();
            $pushed = $events->current();
            self::assertSame('refresh', $pushed->getType());
            self::assertNotSame($initial->getId(), $pushed->getId());
            self::assertTrue(json_decode($pushed->getData(), true, flags: JSON_THROW_ON_ERROR)['refresh']);
        } finally {
            unset($events);
            $subscription->close();
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->delete('project', ['id' => $project->getId()]);
        }
    }
}
