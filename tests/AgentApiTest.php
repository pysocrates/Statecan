<?php

namespace App\Tests;

use App\Entity\Agent;
use App\Entity\CompletionReceipt;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Receipt\ReceiptProcessor;
use App\Receipt\ReceiptStorage;
use App\Runtime\OutboxDispatcher;
use App\Service\TaskTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgentApiTest extends WebTestCase
{
    public function testAgentCompletesTaskWithVerifiableReceipt(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(32));
        $agent = new Agent('API smoke', hash('sha256', $token));
        $project = new Project('Agent API smoke');
        $task = new Task($project, 'Produce evidence');
        $em->persist($agent);
        $em->persist($project);
        $em->persist($task);
        $em->persist(new TaskContract($task, 1, ['objective' => 'Produce evidence']));
        $em->flush();
        self::getContainer()->get(TaskTransitionService::class)->transition($task->getId(), 'ready', 1, 'test-operator');
        $id = $task->getId();
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'];
        $client->request('POST', '/api/v1/agents/sessions', server: $headers + ['HTTP_IDEMPOTENCY_KEY' => 'register-'.bin2hex(random_bytes(8))]);
        self::assertResponseStatusCodeSame(201);
        $session = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $client->request('GET', '/api/v1/agents/sessions/'.$session['id'], server: $headers);
        self::assertResponseIsSuccessful();
        $input = ['sessionId' => $session['id'], 'token' => bin2hex(random_bytes(32)), 'expectedVersion' => 2];
        $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/claim', $input, $headers + ['HTTP_IDEMPOTENCY_KEY' => 'claim-'.$id]);
        self::assertResponseIsSuccessful();
        $lease = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $lease['generation']);
        $input['generation'] = $lease['generation'];
        $input['expectedVersion'] = $lease['version'];
        $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/heartbeat', $input, $headers + ['HTTP_IDEMPOTENCY_KEY' => 'heartbeat-'.$id]);
        self::assertResponseIsSuccessful();
        $input['target'] = 'running';
        $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/transition', $input, $headers + ['HTTP_IDEMPOTENCY_KEY' => 'run-'.$id]);
        self::assertResponseIsSuccessful();
        $running = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $input['expectedVersion'] = $running['version'];
        unset($input['target']);
        $input['result'] = 'Implemented the deliverable';
        $input['evidence'] = ['commit:example'];
        $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/complete', $input, $headers + ['HTTP_IDEMPOTENCY_KEY' => 'complete-'.$id]);
        self::assertResponseStatusCodeSame(202);
        $pending = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertSame('completion_pending_evidence', $em->find(Task::class, $id)->getStatus());
        $container->get(OutboxDispatcher::class)->dispatch(1000);
        $receiver = $container->get('messenger.transport.receipts');
        $bus = $container->get('messenger.default_bus');
        foreach ($receiver->get() as $envelope) {
            $bus->dispatch($envelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('receipts')));
            $receiver->ack($envelope);
        }
        $em->clear();
        $receipt = $em->find(CompletionReceipt::class, $pending['receiptId']);
        self::assertSame('verified', $receipt->getStatus());
        self::assertSame('completed', $receipt->getTask()->getStatus());
        self::assertSame(hash('sha256', $receipt->getCanonicalContent()), $container->get(ReceiptStorage::class)->verify($receipt));
        self::assertSame(0, $container->get(ReceiptProcessor::class)->reconcile()['inconsistent']);
        $client->request('GET', '/api/v1/tasks/'.$id.'/receipts', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('verified', $client->getResponse()->getContent());
    }
}
