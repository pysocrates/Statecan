<?php

namespace App\Tests;

use App\Entity\{Agent, AgentSession, CompletionReceipt, Project, TaskLease, User};
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaskInspectorTest extends WebTestCase
{
    public function testFocusedInspectorShowsRealBoundedTaskDetails(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $user = new User();
        $user->setEmail('inspector-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword('test-only-unusable-password-hash');
        $user->grantOperator();
        $project = new Project('Inspector fixture');
        $agent = new Agent('Inspector agent', hash('sha256', random_bytes(32)));
        $session = new AgentSession($agent, 'inspector-session');
        foreach ([$user, $project, $agent, $session] as $entity) { $em->persist($entity); }
        $em->flush();
        $tasks = self::getContainer()->get(TaskService::class);
        $contract = ['schemaVersion' => 1, 'objective' => 'Structured objective <safe>', 'authorizedScope' => ['src/'], 'constraints' => ['Keep authentication'], 'dependencies' => [], 'acceptanceCriteria' => ['Inspectable'], 'expectedDeliverables' => ['Code']];
        $task = $tasks->create($project->getId(), 'Inspector task', $contract, 'inspector-fixture');
        for ($i = 0; $i < 26; ++$i) { $task = $tasks->revise($task->getId(), 'Inspector task', null, $task->getVersion(), 'inspector-fixture'); }
        $lease = new TaskLease($task, $session, hash('sha256', 'private-lease-token'), 3, new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')), 'inspector-lease');
        $receipt = new CompletionReceipt($task, $session, 3, 1, 'inspector-receipt', bin2hex(random_bytes(18)), '{}');
        $receipt->verify(hash('sha256', '{}'));
        $em->persist($lease);
        $em->persist($receipt);
        $em->flush();
        try {
            $client->loginUser($user);
            $url = '/api/v1/observability/tasks/'.$task->getId().'/inspector?project='.$project->getId();
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $body = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Inspector task', $body['task']['title']);
            self::assertSame('Structured objective <safe>', $body['contract']['document']['objective']);
            self::assertSame('Inspector agent', $body['lease']['agent_name']);
            self::assertSame(3, $body['lease']['generation']);
            self::assertCount(25, $body['events']);
            self::assertTrue($body['has_next']['events']);
            self::assertSame('verified', $body['receipts'][0]['status']);
            self::assertSame(hash('sha256', '{}'), $body['receipts'][0]['hash']);
            self::assertArrayNotHasKey('token_hash', $body['lease']);
            self::assertArrayNotHasKey('canonical_content', $body['receipts'][0]);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', $url.'&page=1');
            self::assertResponseIsSuccessful();
            self::assertCount(2, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['events']);
            $client->request('GET', '/dashboard/tasks/'.$task->getId().'/inspector?project='.$project->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#inspector-title', 'Inspector task');
            self::assertSelectorTextContains('[data-inspector-section="contract"]', 'Structured objective <safe>');
            self::assertSelectorTextContains('[data-inspector-section="ownership"]', 'Inspector agent');
            self::assertSelectorTextContains('[data-inspector-section="receipts"]', 'Verified');
            self::assertSelectorExists('[data-inspector-page="1"]');
            // The dashboard supplies this same focused projection for an initial SSR selection.
            $body['contract']['document']['resourceBudgets'] = ['time' => 60, 'tokens' => ['soft' => 1000, 'labels' => ['<safe>']]];
            $html = self::getContainer()->get(\Twig\Environment::class)->render('observability/_inspector.html.twig', ['initial_inspector' => $body]);
            $rendered = new \Symfony\Component\DomCrawler\Crawler($html);
            self::assertCount(1, $rendered->filter('#task-inspector:not([hidden])'));
            self::assertSame($task->getId(), $rendered->filter('#task-inspector')->attr('data-task-id'));
            self::assertCount(1, $rendered->filter('#inspector-content #inspector-title'));
            self::assertStringContainsString('Inspector task', $rendered->filter('#inspector-title')->text());
            self::assertStringContainsString('soft', $rendered->filter('[data-inspector-section="contract"]')->text());
            self::assertStringContainsString('<safe>', $rendered->filter('[data-inspector-section="contract"]')->text());
            self::assertStringNotContainsString('<safe>', $html);

        } finally {
            $db->executeStatement('DELETE FROM completion_receipt WHERE task_id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM task_lease WHERE task_id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM outbox_event WHERE aggregate_id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM state_event WHERE task_id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM task_contract WHERE task_id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM task WHERE id = ?', [$task->getId()]);
            $db->executeStatement('DELETE FROM project WHERE id = ?', [$project->getId()]);
            $db->executeStatement('DELETE FROM agent_session WHERE id = ?', [$session->getId()]);
            $db->executeStatement('DELETE FROM agent WHERE id = ?', [$agent->getId()]);
            $db->executeStatement('DELETE FROM app_user WHERE email = ?', [$user->getEmail()]);
        }
    }
}
