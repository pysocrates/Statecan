<?php

namespace App\Tests\Observability;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Observability\ActivityInvalidation;
use App\Observability\PostgresActivityListener;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TargetedUpdatesTest extends WebTestCase
{
    public function testCommittedEntityNotificationAndScopedAuthoritativeRead(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $user = new User();
        $user->setEmail('targeted-'.bin2hex(random_bytes(8)).'@example.invalid');
        $user->setPassword('not-used-by-loginUser');
        $user->grantOperator();
        $project = new Project('Targeted update smoke');
        $task = new Task($project, 'Before update');
        $subscription = null;
        try {
            foreach ([$user, $project, $task] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $client->loginUser($user);
            $listener = self::getContainer()->get(PostgresActivityListener::class);
            $subscription = $listener->subscribe();
            $db->update('task', ['title' => 'After update', 'version' => 2], ['id' => $task->getId()]);
            $batch = $listener->changes($subscription, 1000);
            $invalidation = self::getContainer()->get(ActivityInvalidation::class)->batch($batch['changes'], $project->getId());
            self::assertContains('work', $invalidation['components']);
            self::assertSame($task->getId(), $invalidation['entities'][0]['id']);
            self::assertSame('task', $invalidation['entities'][0]['type']);
            self::assertSame('UPDATE', $invalidation['entities'][0]['operation']);
            self::assertNotEmpty($invalidation['entities'][0]['revision']);
            $client->request('GET', '/api/v1/observability/components/work?project='.$project->getId());
            self::assertResponseIsSuccessful();
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
            $work = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('After update', $work['work_groups']['ready'][0]['title']);
            self::assertArrayNotHasKey('repositories', $work);
            self::assertArrayNotHasKey('sessions', $work);
            $client->request('GET', '/dashboard/components/work?project='.$project->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-console-component="work"]');
            self::assertSelectorTextContains('.task-card', 'After update');
            $client->request('GET', '/api/v1/observability/components/projects');
            self::assertResponseIsSuccessful();
            $projects = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertContains($project->getId(), array_column($projects['projects'], 'id'));
            self::assertSame(['projects', 'project', 'page'], array_keys($projects));
            $heartbeat = self::getContainer()->get(ActivityInvalidation::class)->batch([['table' => 'agent_session', 'id' => 'session-id', 'revision' => 'revision']], $project->getId());
            self::assertNotContains('work', $heartbeat['components']);
            self::assertNotContains('git', $heartbeat['components']);
        } finally {
            $subscription?->close();
            $db->delete('task', ['id' => $task->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            if (null !== $user->getId()) {
                $db->delete('app_user', ['id' => $user->getId()]);
            }
        }
    }
}
