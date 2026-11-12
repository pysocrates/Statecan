<?php

namespace App\Tests\Git;

use App\Entity\Agent;
use App\Entity\AgentSession;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Git\GitQuery;
use App\Runtime\OutboxDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Process\Process;

final class GitWorkflowTest extends WebTestCase
{
    public function testRepositoryIsQueuedIndexedAndCorrelated(): void
    {
        $path = sys_get_temp_dir().'/statecan-git-'.bin2hex(random_bytes(6));
        mkdir($path);
        $previous = $_ENV['STATECAN_GIT_ROOT'] ?? null;
        $_ENV['STATECAN_GIT_ROOT'] = $_SERVER['STATECAN_GIT_ROOT'] = $path;
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $container->get(Connection::class);
        $project = new Project('Git workflow');
        $task = new Task($project, 'Correlate a commit');
        $operator = new User();
        $operator->setEmail('git-'.bin2hex(random_bytes(6)).'@example.com');
        $operator->setPassword('unused-test-password');
        $operator->grantOperator();
        $em->persist($operator);
        $agent = new Agent('Git author', hash('sha256', random_bytes(32)));
        $session = new AgentSession($agent, 'git-workflow');
        $em->persist($agent);
        $em->persist($session);
        $em->persist($project);
        $em->persist($task);
        $em->flush();
        $client->loginUser($operator);
        $repositoryId = $jobId = $syncJobId = null;
        try {
            (new Process(['git', 'init', '-b', 'main', $path]))->mustRun();
            file_put_contents($path.'/hello.txt', 'hello');
            (new Process(['git', '-C', $path, 'add', 'hello.txt']))->mustRun();
            (new Process(['git', '-C', $path, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', "Initial\n\nStatecan-Task: ".$task->getId()."\nStatecan-Session: ".$session->getId()."\nStatecan-Agent: ".$agent->getId()]))->mustRun();
            $input = ['projectId' => $project->getId(), 'path' => $path];
            $client->jsonRequest('POST', '/api/v1/repositories', $input, ['HTTP_IDEMPOTENCY_KEY' => 'workflow']);
            self::assertResponseStatusCodeSame(202);
            $registration = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $repositoryId = $registration['id'];
            $jobId = $registration['jobId'];
            $client->jsonRequest('POST', '/api/v1/repositories', $input, ['HTTP_IDEMPOTENCY_KEY' => 'workflow']);
            self::assertResponseStatusCodeSame(202);
            self::assertSame($registration, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
            // Exercise durable enqueue and the actual Messenger handler/listener.
            $consume = static function () use ($container): bool {
                $container->get(OutboxDispatcher::class)->dispatch();
                $transport = $container->get('messenger.transport.git_indexing');
                $handled = false;
                foreach ($transport->get() as $envelope) {
                    $container->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('git_indexing')));
                    $transport->ack($envelope);
                    $handled = true;
                }

                return $handled;
            };
            self::assertTrue($consume());
            $query = $container->get(GitQuery::class);
            $client->request('GET', '/api/v1/repositories/'.$repositoryId.'/events');
            self::assertResponseIsSuccessful();
            $events = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $events['events']);
            self::assertArrayHasKey('refs/heads/main', $events['refs']);
            $client->request('GET', '/api/v1/tasks/'.$task->getId().'/git');
            self::assertResponseIsSuccessful();
            $associations = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['associations'];
            self::assertCount(1, $associations);
            self::assertSame('identities_validated', $associations[0]['verification_status']);
            self::assertSame($session->getId(), $associations[0]['session_id']);
            self::assertSame('self_reported', $associations[0]['confidence']);
            $client->request('GET', '/api/v1/repositories/'.$repositoryId.'/graph');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(substr($associations[0]['sha'], 0, 12), json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['mermaid']);
            $client->jsonRequest('POST', '/api/v1/repositories/'.$repositoryId.'/sync', [], ['HTTP_IDEMPOTENCY_KEY' => 'sync-workflow']);
            self::assertResponseStatusCodeSame(202);
            $syncJobId = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['jobId'];
            self::assertTrue($consume());
            self::assertCount(2, $query->events($repositoryId)['events']);
            self::assertSame('completed', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$jobId]));
        } finally {
            if (null !== $repositoryId) {
                $db->delete('git_task_association', ['repository_id' => $repositoryId]);
                $db->delete('git_event', ['repository_id' => $repositoryId]);
                $db->delete('git_repository', ['id' => $repositoryId]);
            }
            foreach (array_filter([$jobId, $syncJobId]) as $id) {
                $db->delete('outbox_event', ['id' => $id]);
                $db->delete('background_job', ['id' => $id]);
            }
            $em->clear();
            $db->delete('task', ['id' => $task->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            $db->delete('app_user', ['id' => $operator->getId()]);
            $db->delete('agent_session', ['id' => $session->getId()]);
            $db->delete('agent', ['id' => $agent->getId()]);
            static::ensureKernelShutdown();
            if (null === $previous) {
                unset($_ENV['STATECAN_GIT_ROOT'], $_SERVER['STATECAN_GIT_ROOT']);
            } else {
                $_ENV['STATECAN_GIT_ROOT'] = $_SERVER['STATECAN_GIT_ROOT'] = $previous;
            }
            (new Filesystem())->remove($path);
        }
    }
}
