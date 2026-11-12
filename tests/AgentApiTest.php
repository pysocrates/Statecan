<?php

namespace App\Tests;

use App\Agent\LeaseService;
use App\Entity\Agent;
use App\Entity\CompletionReceipt;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Entity\TaskLease;
use App\Receipt\ReceiptStorage;
use App\Entity\OutboxEvent;
use App\Message\OutboxMessage;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use App\Service\TaskTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgentApiTest extends WebTestCase
{
    public function testAgentCompletesTaskWithVerifiableReceipt(): void
    {
        $this->completeTask(false);
    }

    public function testLeaseExpiryDuringReceiptMaterializationPreservesPendingCompletion(): void
    {
        $this->completeTask(true);
    }

    private function completeTask(bool $expireDuringMaterialization): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(32));
        $agent = new Agent('API smoke', hash('sha256', $token));
        $project = new Project('Agent API smoke');
        $task = new Task($project, 'Produce evidence');
        $directory = sys_get_temp_dir().'/statecan-receipts-'.bin2hex(random_bytes(12));
        $previousServerDirectory = $_SERVER['STATECAN_RECEIPT_DIR'] ?? null;
        $previousEnvDirectory = $_ENV['STATECAN_RECEIPT_DIR'] ?? null;
        $_SERVER['STATECAN_RECEIPT_DIR'] = $_ENV['STATECAN_RECEIPT_DIR'] = $directory;
        $messageId = null;
        try {
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
            if ($expireDuringMaterialization) {
                // Interleave recovery in the storage/DB boundary used by ReceiptProcessor:
                // immutable bytes exist, but receipt metadata and task are not finalized yet.
                $receipt = $em->find(CompletionReceipt::class, $pending['receiptId']);
                $storedHash = $container->get(ReceiptStorage::class)->materialize($receipt);
                self::assertSame('pending', $receipt->getStatus());
                self::assertNull($receipt->getHash());
                $em->getConnection()->executeStatement("UPDATE task_lease SET expires_at = (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '1 second' WHERE task_id = ?", [$id]);
                $em->clear();
                $container->get(LeaseService::class)->recoverExpired();
                $em->clear();
                $recoveredTask = $em->find(Task::class, $id);
                $recoveredLease = $em->find(TaskLease::class, $id);
                self::assertSame('completion_pending_evidence', $recoveredTask->getStatus());
                self::assertSame($pending['taskVersion'], $recoveredTask->getVersion());
                self::assertSame(0, $recoveredLease->getExpiresAt()->getTimestamp());
                self::assertSame($lease['generation'], $recoveredLease->getGeneration());
                self::assertSame('pending', $em->find(CompletionReceipt::class, $pending['receiptId'])->getStatus());
                self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM state_event WHERE task_id = ? AND actor_id = 'lease-recovery'", [$id]));

                $expiredInput = ['sessionId' => $session['id'], 'token' => $input['token'], 'generation' => $lease['generation'], 'expectedVersion' => $pending['taskVersion']];
                $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/heartbeat', $expiredInput, $headers + ['HTTP_IDEMPOTENCY_KEY' => 'expired-heartbeat-'.$id]);
                self::assertResponseStatusCodeSame(409);
                $client->jsonRequest('POST', '/api/v1/tasks/'.$id.'/claim', array_replace($expiredInput, ['token' => bin2hex(random_bytes(32))]), $headers + ['HTTP_IDEMPOTENCY_KEY' => 'pending-reclaim-'.$id]);
                self::assertResponseStatusCodeSame(409);

                // Use a fresh request/kernel after the rejected command's transaction rollback.
                $client->request('GET', '/api/v1/tasks/'.$id, server: $headers);
                self::assertResponseIsSuccessful();
                self::assertSame('completion_pending_evidence', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);
                $container = self::getContainer();
                $em = $container->get(EntityManagerInterface::class);
            }
            $event = $em->getRepository(OutboxEvent::class)->findOneBy(['type' => 'receipt.requested', 'aggregateId' => $pending['receiptId']]);
            self::assertNotNull($event);
            $receiver = $container->get('messenger.transport.receipts');
            $bus = $container->get('messenger.default_bus');
            $sent = $bus->dispatch(new OutboxMessage($event->getId(), $event->getType(), $event->getAggregateId(), $event->getPayload(), $event->getId()), [new TransportNamesStamp(['receipts'])]);
            $messageId = $sent->last(TransportMessageIdStamp::class)->getId();
            $envelope = $receiver->find($messageId);
            self::assertNotNull($envelope);
            $bus->dispatch($envelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('receipts')));
            $receiver->ack($envelope);
            $em->clear();
            $receipt = $em->find(CompletionReceipt::class, $pending['receiptId']);
            self::assertSame('verified', $receipt->getStatus());
            self::assertSame('completed', $receipt->getTask()->getStatus());
            self::assertSame(hash('sha256', $receipt->getCanonicalContent()), $container->get(ReceiptStorage::class)->verify($receipt));
            if ($expireDuringMaterialization) {
                self::assertSame($storedHash, $receipt->getHash());
                self::assertSame($session['id'], $receipt->getSession()->getId());
                self::assertSame($lease['generation'], $receipt->getLeaseGeneration());
                self::assertSame($pending['taskVersion'] + 1, $receipt->getTask()->getVersion());
                self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM completion_receipt WHERE task_id = ?', [$id]));
                self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM state_event WHERE task_id = ? AND to_state = 'completed'", [$id]));
                $container->get(LeaseService::class)->recoverExpired();
                $em->clear();
                self::assertSame('completed', $em->find(Task::class, $id)->getStatus());
            }
            $client->request('GET', '/api/v1/tasks/'.$id.'/receipts', server: $headers);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('verified', $client->getResponse()->getContent());
        } finally {
            try {
                $em = self::getContainer()->get(EntityManagerInterface::class);
                $db = $em->getConnection();
                $receiptIds = $db->fetchFirstColumn('SELECT id FROM completion_receipt WHERE task_id = ?', [$task->getId()]);
                foreach ($receiptIds as $receiptId) {
                    $file = $directory.'/'.$receiptId.'.json';
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                if (is_dir($directory)) {
                    rmdir($directory);
                }
                $db->transactional(function () use ($db, $task, $agent, $project, $messageId, $receiptIds) {
                    if (null !== $messageId) {
                        $db->delete('messenger_messages', ['id' => $messageId]);
                    }
                    foreach ([$task->getId(), ...$receiptIds] as $aggregateId) {
                        $db->executeStatement('DELETE FROM background_job WHERE id IN (SELECT id FROM outbox_event WHERE aggregate_id = ?)', [$aggregateId]);
                        $db->delete('outbox_event', ['aggregate_id' => $aggregateId]);
                    }
                    $db->delete('task_lease', ['task_id' => $task->getId()]);
                    $db->delete('completion_receipt', ['task_id' => $task->getId()]);
                    $db->delete('state_event', ['task_id' => $task->getId()]);
                    $db->delete('task_contract', ['task_id' => $task->getId()]);
                    $db->delete('task', ['id' => $task->getId()]);
                    $db->delete('project', ['id' => $project->getId()]);
                    $db->delete('agent_operation', ['agent_id' => $agent->getId()]);
                    $db->delete('agent_session', ['agent_id' => $agent->getId()]);
                    $db->delete('agent', ['id' => $agent->getId()]);
                });
                $em->clear();
            } finally {
                if (null === $previousServerDirectory) {
                    unset($_SERVER['STATECAN_RECEIPT_DIR']);
                } else {
                    $_SERVER['STATECAN_RECEIPT_DIR'] = $previousServerDirectory;
                }
                if (null === $previousEnvDirectory) {
                    unset($_ENV['STATECAN_RECEIPT_DIR']);
                } else {
                    $_ENV['STATECAN_RECEIPT_DIR'] = $previousEnvDirectory;
                }
            }
        }
    }
}
