<?php

namespace App\Tests\Observability;

use App\Entity\Agent;
use App\Entity\AgentSession;
use App\Entity\CompletionReceipt;
use App\Entity\GitRepository;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Entity\User;
use App\Service\TaskTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DashboardTest extends WebTestCase
{
    public function testOperatorInspectsAndRefreshesAuthoritativeTaskState(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('observer-'.bin2hex(random_bytes(6)).'@example.com');
        $user->setPassword('unused-test-password');
        $user->grantOperator();
        $project = new Project('Observability smoke');
        $task = new Task($project, 'Inspect real task');
        $contract = new TaskContract($task, 1, ['objective' => 'Display current state']);
        $agent = new Agent('Smoke agent', hash('sha256', random_bytes(32)));
        $session = new AgentSession($agent, 'smoke-session');
        $receipt = new CompletionReceipt($task, $session, 1, 1, 'smoke-receipt', bin2hex(random_bytes(18)), '{"result":"smoke"}');
        $repository = new GitRepository(Uuid::v7()->toRfc4122(), $project, 'fixture-observation-'.bin2hex(random_bytes(8)));
        foreach ([$user, $project, $task, $contract, $agent, $session, $receipt, $repository] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($user);
        $query = http_build_query(['project' => $project->getId(), 'task' => $task->getId()]);
        $client->request('GET', '/dashboard?'.$query);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#task-inspector', 'Inspect real task');
        self::assertSelectorExists('#task-inspector [data-inspector-section="git"]');
        $client->request('GET', '/api/v1/observability/snapshot?'.$query);
        self::assertResponseIsSuccessful();
        $snapshot = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('backlog', $snapshot['task']['status']);
        self::assertCount(6, $snapshot['queues']);
        self::assertSame('Smoke agent', $snapshot['sessions'][0]['agent']);
        self::assertSame('pending', $snapshot['receipts'][0]['status']);
        self::assertSame($repository->getId(), $snapshot['repositories'][0]['graph']['repositoryId']);
        $client->request('GET', '/api/v1/observability/receipts/'.$receipt->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('{"result":"smoke"}', json_decode($client->getResponse()->getContent(), true)['canonical_content']);
        static::getContainer()->get(TaskTransitionService::class)->transition($task->getId(), 'ready', 1, $user->getUserIdentifier());
        // A reconnect notification directs the browser to refresh authoritative state.
        $client->request('GET', '/api/v1/observability/events?'.$query);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/event-stream; charset=UTF-8');
        $events = $client->getInternalResponse()->getContent();
        self::assertStringContainsString('event: refresh', $events);
        $client->request('GET', '/dashboard/tasks/'.$task->getId().'/inspector?project='.$project->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.inspector-heading', 'ready');
        self::assertSelectorTextContains('.inspector-heading', 'Task v2');
    }
}
