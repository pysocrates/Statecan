<?php

namespace App\Tests;

use App\Entity\{Project, User};
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoreApiTest extends WebTestCase
{
    public function testAuthenticatedTaskInspection(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('core-'.bin2hex(random_bytes(6)).'@example.com');
        $user->setPassword('test-only-unusable-password-hash');
        $project = new Project('API smoke project');
        $em->persist($user);
        $em->persist($project);
        $em->flush();
        $task = self::getContainer()->get(TaskService::class)->create($project->getId(), 'Inspect core task', ['schemaVersion' => 1, 'objective' => 'Inspect task', 'authorizedScope' => [], 'constraints' => [], 'dependencies' => [], 'acceptanceCriteria' => ['Visible'], 'expectedDeliverables' => ['JSON']], 'test');
        $taskId = $task->getId();
        $client->loginUser($user);
        $client->request('GET', '/api/v1/tasks/'.$taskId, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Inspect core task', $body['title']);
        self::assertSame('Inspect task', $body['contract']['objective']);
        $client->request('GET', '/api/v1/tasks/'.$taskId.'/events', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $events = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('backlog', $events[0]['to']);
        // Remove only this test's data; production deletion remains archival.
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM outbox_event WHERE aggregate_id = ?', [$taskId]);
        $connection->executeStatement('DELETE FROM state_event WHERE task_id = ?', [$taskId]);
        $connection->executeStatement('DELETE FROM task_contract WHERE task_id = ?', [$taskId]);
        $connection->executeStatement('DELETE FROM task WHERE id = ?', [$taskId]);
        $connection->executeStatement('DELETE FROM project WHERE id = ?', [$project->getId()]);
        $connection->executeStatement('DELETE FROM app_user WHERE email = ?', [$user->getEmail()]);
    }
}
