<?php

namespace App\Tests;

use App\Entity\Agent;
use App\Entity\AgentSession;
use App\Entity\CompletionReceipt;
use App\Entity\GitRepository;
use App\Entity\Project;
use App\Entity\StateEvent;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class GitHistoryTest extends WebTestCase
{
    public function testFocusedGitAndAllHistoricalRecordTypes(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $user = new User();
        $user->setEmail('review-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword('unused');
        $user->grantOperator();
        $project = new Project('Review fixture');
        $task = new Task($project, 'Review provenance');
        $agent = new Agent('Review agent', hash('sha256', random_bytes(32)));
        $session = new AgentSession($agent, 'review');
        $repository = new GitRepository(Uuid::v7()->toRfc4122(), $project, 'fixture:'.bin2hex(random_bytes(8)));
        $receipt = new CompletionReceipt($task, $session, 1, 1, 'review', Uuid::v7()->toRfc4122(), '{}');
        $contract = new TaskContract($task, 1, ['objective' => 'Inspect real provenance', 'schemaVersion' => 1, 'authorizedScope' => ['src/'], 'constraints' => [], 'dependencies' => [], 'expectedDeliverables' => ['Indexed evidence'], 'acceptanceCriteria' => ['Keep identity confidence honest']]);
        foreach ([$user, $project, $task, $agent, $session, $repository, $receipt, $contract] as $entity) {
            $em->persist($entity);
        }
        for ($index = 1; $index <= 26; ++$index) {
            $em->persist(new StateEvent($task, 'backlog', 'ready', $index, 'review-actor', ['sessionId' => $session->getId()]));
        }
        $em->flush();
        $sha = str_repeat('a', 40);
        $parent = str_repeat('b', 40);
        $at = gmdate('Y-m-d H:i:s');
        $db->executeStatement('UPDATE git_repository SET refs = ?, observed_at = ? WHERE id = ?', [json_encode(['refs/heads/main' => $sha]), $at, $repository->getId()]);
        foreach ([$sha, $parent] as $commit) {
            $db->insert('git_event', ['id' => Uuid::v7()->toRfc4122(), 'repository_id' => $repository->getId(), 'event_key' => 'commit:'.$commit, 'kind' => 'commit', 'sha' => $commit, 'observed_at' => $at, 'metadata' => json_encode(['message' => 'Review <script>unsafe</script>', 'author' => 'Recorded author', 'authoredAt' => '2026-01-01T00:00:00Z', 'parents' => $commit === $sha ? [$parent] : [], 'files' => ['src/example.php']])]);
        }
        $db->insert('git_task_association', ['id' => Uuid::v7()->toRfc4122(), 'repository_id' => $repository->getId(), 'sha' => $sha, 'task_id' => $task->getId(), 'session_id' => $session->getId(), 'agent_id' => $agent->getId(), 'source' => 'git_trailer', 'confidence' => 'self_reported', 'verification_status' => 'identities_validated', 'observed_at' => $at]);
        try {
            $client->loginUser($user);
            $parameters = ['project' => $project->getId(), 'repository' => $repository->getId(), 'branch' => 'refs/heads/main'];
            $client->request('GET', '/api/v1/observability/git', $parameters);
            self::assertResponseIsSuccessful();
            $git = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $git['events']);
            self::assertCount(2, $git['graph']['nodes']);
            self::assertStringContainsString('-->', $git['mermaid']);
            self::assertStringNotContainsString('fixture:', $client->getResponse()->getContent());
            $client->request('GET', '/dashboard/git', $parameters);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-console-component="git"] [data-graph-fit]');
            self::assertSelectorExists('[data-git-commit="'.$sha.'"]');
            self::assertSelectorTextContains('.git-caveat', 'do not authenticate authorship');
            self::assertSelectorNotExists('script');
            $client->request('GET', '/dashboard/git/commits/'.$sha, $parameters);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.git-commit-detail', 'Review provenance');
            foreach (['transitions', 'sessions', 'receipts', 'contracts', 'git'] as $kind) {
                $query = ['project' => $project->getId(), 'kind' => $kind, 'q' => 'Review'];
                $client->request('GET', '/api/v1/observability/history', $query);
                self::assertResponseIsSuccessful();
                $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertNotEmpty($result['rows'], $kind);
                self::assertLessThanOrEqual(25, count($result['rows']));
                if ('transitions' === $kind) {
                    self::assertTrue($result['has_next']);
                }
                $client->request('GET', '/dashboard/history', $query);
                self::assertResponseIsSuccessful();
                self::assertSelectorExists('[data-console-component="history"] [data-inspect-task]');
                if ('contracts' === $kind) {
                    self::assertSelectorTextContains('.history-detail', 'Keep identity confidence honest');
                }
            }
            $client->request('GET', '/api/v1/observability/history', ['project' => $project->getId(), 'page' => 1]);
            self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['rows']);
        } finally {
            foreach (['git_task_association', 'git_event'] as $table) {
                $db->delete($table, ['repository_id' => $repository->getId()]);
            }
            $db->delete('git_repository', ['id' => $repository->getId()]);
            foreach (['completion_receipt', 'task_contract', 'state_event'] as $table) {
                $db->delete($table, ['task_id' => $task->getId()]);
            }
            $db->delete('task', ['id' => $task->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            $db->delete('agent_session', ['id' => $session->getId()]);
            $db->delete('agent', ['id' => $agent->getId()]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
